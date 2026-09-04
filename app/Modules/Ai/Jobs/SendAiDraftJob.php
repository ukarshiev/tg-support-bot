<?php

namespace App\Modules\Ai\Jobs;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\RussianOperatorTextService;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAiDraftJob implements ShouldQueue
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
     * Generate an AI draft and persist it for the admin panel workspace.
     * Queues a separate Telegram delivery only after persistence succeeds.
     *
     * @param AiAssistantService $aiService
     *
     * @return void
     */
    public function handle(AiAssistantService $aiService): void
    {
        try {
            $botUser = BotUser::find($this->botUserId);
            if ($botUser === null) {
                throw new \RuntimeException('BotUser not found: ' . $this->botUserId, 1);
            }

            $aiBotToken = (string) app(SettingsService::class)->get('telegram_ai.token');
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
            $aiBotAvailable = $aiBotToken !== '' && $groupId !== '';

            if ($aiBotAvailable && empty($botUser->topic_id)) {
                TopicCreateJob::dispatch($botUser->id);
                Log::channel('app')->info('SendAiDraftJob: topic pending, draft will remain in admin workspace', [
                    'source' => 'send_ai_draft_topic_pending',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
            }

            // Generate AI draft text using the existing service.
            $aiRequest = new AiRequestDto(
                message: $this->userMessage,
                userId: $this->botUserId,
                platform: $botUser->platform ?? 'telegram',
                provider: (string) app(SettingsService::class)->get('ai.default_provider'),
                forceEscalation: false,
                // Источник для оператора всегда русский. Клиентский язык делаем отдельным переводом ниже.
                preferredLanguageCode: 'ru',
                preferredLanguageName: 'Русский'
            );

            $aiResponse = $aiService->processMessage($aiRequest);
            if ($aiResponse === null || trim((string) $aiResponse->response) === '') {
                Log::channel('app')->warning('AI provider returned an empty response; draft was not created', [
                    'source' => 'send_ai_draft_empty_provider_response',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                    'provider' => $aiRequest->provider,
                ]);

                throw new \RuntimeException('AI provider returned an empty response; draft was not created', 1);
            }

            $sourceText = app(RussianOperatorTextService::class)->normalize($aiResponse->response);
            if (trim($sourceText) === '') {
                Log::channel('app')->warning('AI response became empty after normalization; draft was not created', [
                    'source' => 'send_ai_draft_empty_normalized_response',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                    'provider' => $aiRequest->provider,
                ]);

                throw new \RuntimeException('AI response became empty after normalization; draft was not created', 1);
            }

            [$targetLocale, $translatedText, $translationProvider, $translationStatus] = $this->translateDraft($botUser, $sourceText);

            // Сохранение — граница надёжности: Telegram вызывается только отдельной
            // job после появления записи, видимой оператору в админке.
            $aiMessage = AiMessage::create([
                'bot_user_id' => $botUser->id,
                'message_id' => null,
                'text_ai' => $sourceText,
                'text_source' => $sourceText,
                'text_translated' => $translatedText,
                'source_locale' => 'ru',
                'target_locale' => $targetLocale,
                'translation_provider' => $translationProvider,
                'translation_status' => $translationStatus,
                'source_hash' => hash('sha256', trim($sourceText)),
                'text_manager' => '',
                'status' => AiMessage::STATUS_PENDING,
            ]);

            if ($aiBotAvailable) {
                SendPendingAiDraftToTelegramJob::dispatch($aiMessage->id);
            }

            Log::channel('app')->info('SendAiDraftJob: draft persisted before Telegram delivery', [
                'source' => 'send_ai_draft_persisted',
                'ai_message_id' => $aiMessage->id,
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'telegram_delivery_queued' => $aiBotAvailable,
            ]);

            $slaMinutes = max(1, (int) (app(SettingsService::class)->get('ai.draft_sla_minutes') ?: 15));
            AlertStaleAiDraftJob::dispatch($aiMessage->id, $slaMinutes)
                ->delay(now()->addMinutes($slaMinutes));
        } catch (\Throwable $e) {
            Log::channel('app')->log(
                $e->getCode() === 1 ? 'warning' : 'error',
                $e->getMessage(),
                ['source' => 'send_ai_draft_error', 'file' => $e->getFile(), 'line' => $e->getLine()]
            );

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->critical('AI draft generation permanently failed', [
            'source' => 'send_ai_draft_failed_terminal',
            'bot_user_id' => $this->botUserId,
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array{string|null, string|null, string|null, string}
     */
    private function translateDraft(BotUser $botUser, string $sourceText): array
    {
        $targetLocale = $botUser->preferred_language_code;
        if ($targetLocale === null || $targetLocale === '' || $targetLocale === 'ru') {
            return [$targetLocale ?: null, $sourceText, 'same_locale', 'ready'];
        }

        $result = app(TranslationService::class)->translate(new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: $targetLocale,
            text: $sourceText,
            purpose: 'ai_draft',
        ));

        return [
            $targetLocale,
            $result->success ? $result->text : null,
            $result->provider,
            $result->success ? 'ready' : 'error',
        ];
    }
}
