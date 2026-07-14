<?php

namespace App\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Ai\Services\RussianOperatorTextService;
use App\Modules\Telegram\Actions\SendTypingAction;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAiReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param int                    $botUserId   BotUser primary key
     * @param TelegramUpdateDto|null $updateDto   Parsed webhook update; null when AI is triggered
     *                                            from a non-Telegram source (e.g. VK/Max).
     * @param string                 $userMessage Original user message text to send to AI
     */
    public function __construct(
        public readonly int $botUserId,
        public readonly ?TelegramUpdateDto $updateDto,
        public readonly string $userMessage,
    ) {
        $this->onQueue('ai');
    }

    /**
     * Generate an AI reply, deliver it to the user, and post it to the
     * supergroup forum topic when the Telegram AI bot is configured.
     *
     * @param AiBotApi           $aiBotApi
     * @param AiAssistantService $aiService
     *
     * @return void
     */
    public function handle(AiBotApi $aiBotApi, AiAssistantService $aiService): void
    {
        try {
            $botUser = BotUser::find($this->botUserId);
            if ($botUser === null) {
                throw new \RuntimeException('BotUser not found: ' . $this->botUserId, 1);
            }

            app(SendTypingAction::class)->execute($botUser);

            $aiRequest = new AiRequestDto(
                message: $this->userMessage,
                userId: $this->botUserId,
                platform: $botUser->platform ?? 'telegram',
                provider: (string) app(SettingsService::class)->get('ai.default_provider'),
                forceEscalation: false,
                // Источник AI-ответа всегда русский; доставка клиенту переводится отдельно.
                preferredLanguageCode: 'ru',
                preferredLanguageName: 'Русский'
            );

            $aiResponse = $aiService->processMessage($aiRequest);
            if ($aiResponse === null || trim((string) $aiResponse->response) === '') {
                throw new \RuntimeException('AI provider returned empty response', 1);
            }

            $sourceReplyText = app(RussianOperatorTextService::class)->normalize($aiResponse->response);
            [$targetLocale, $replyText, $translationProvider, $translationStatus] = $this->translateReply($botUser, $sourceReplyText);

            $aiMessage = AiMessage::create([
                'bot_user_id' => $botUser->id,
                'message_id' => null,
                'text_ai' => $sourceReplyText,
                'text_source' => $sourceReplyText,
                'text_translated' => $replyText,
                'source_locale' => 'ru',
                'target_locale' => $targetLocale,
                'translation_provider' => $translationProvider,
                'translation_status' => $translationStatus,
                'source_hash' => hash('sha256', trim($sourceReplyText)),
                'text_manager' => $sourceReplyText,
                'status' => 'delivery_pending',
            ]);

            DeliverAiMessageJob::dispatch($aiMessage->id, $this->updateDto);

            Log::channel('app')->info('SendAiReplyJob: AI reply queued for confirmed delivery', [
                'source' => 'ai_reply_delivery_queued',
                'ai_message_id' => $aiMessage->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'locale' => $targetLocale,
            ]);
        } catch (\Throwable $e) {
            Log::channel('app')->log(
                $e->getCode() === 1 ? 'warning' : 'error',
                $e->getMessage(),
                ['source' => 'send_ai_reply_error', 'file' => $e->getFile(), 'line' => $e->getLine()]
            );

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->critical('AI reply generation permanently failed', [
            'source' => 'send_ai_reply_failed_terminal',
            'bot_user_id' => $this->botUserId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array{string|null, string|null, string|null, string}
     */
    private function translateReply(BotUser $botUser, string $sourceText): array
    {
        $targetLocale = $botUser->preferred_language_code;
        if ($targetLocale === 'ru') {
            return [
                $targetLocale,
                $sourceText,
                'same_locale',
                'ready',
            ];
        }

        if ($targetLocale === null || $targetLocale === '') {
            return [null, $this->safeEnglishFallback(), 'builtin_safe_english', 'ready'];
        }

        $result = app(TranslationService::class)->translate(new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: $targetLocale,
            text: $sourceText,
            purpose: 'ai_auto_reply',
        ));

        if ($result->success && trim((string) $result->text) !== '') {
            return [$targetLocale, $result->text, $result->provider, 'ready'];
        }

        if ($targetLocale !== 'en') {
            $english = app(TranslationService::class)->translate(new TranslationRequest(
                sourceLocale: 'ru',
                targetLocale: 'en',
                text: $sourceText,
                purpose: 'ai_auto_reply_english_fallback',
            ));

            if ($english->success && trim((string) $english->text) !== '') {
                return [$targetLocale, $english->text, 'english_fallback:' . $english->provider, 'ready'];
            }
        }

        return [$targetLocale, $this->safeEnglishFallback(), 'builtin_safe_english', 'ready'];
    }

    private function safeEnglishFallback(): string
    {
        return 'A support agent will reply shortly. We could not prepare a safe localized answer.';
    }
}
