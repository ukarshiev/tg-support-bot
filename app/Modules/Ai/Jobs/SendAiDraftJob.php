<?php

namespace App\Modules\Ai\Jobs;

use App\Helpers\AiHelper;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Admin\Services\ChannelStatusService;
use App\Modules\Ai\DTOs\AiRequestDto;
use App\Modules\Ai\Services\AiAssistantService;
use App\Modules\Ai\Services\AiBotApi;
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
     * Additionally posts the draft to the supergroup forum topic when the
     * Telegram AI bot is configured (telegram_ai.token is set).
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

            $aiBotToken = (string) app(SettingsService::class)->get('telegram_ai.token');
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
            $telegramConnected = app(ChannelStatusService::class)->telegram()['connected']
                && $groupId !== '';
            $aiBotConfigured = $aiBotToken !== '' && $telegramConnected;

            // Черновик сначала гарантированно сохраняется в админке. Отсутствие
            // Telegram-темы не должно исчерпать попытки и потерять ответ ИИ.
            if ($aiBotConfigured && empty($botUser->topic_id)) {
                TopicCreateJob::dispatch($botUser->id);
                $aiBotConfigured = false;
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
            if ($aiResponse === null) {
                throw new \RuntimeException('AI provider returned null', 1);
            }

            $sourceText = app(RussianOperatorTextService::class)->normalize($aiResponse->response);
            [$targetLocale, $translatedText, $translationProvider, $translationStatus] = $this->translateDraft($botUser, $sourceText);

            if ($aiBotConfigured) {
                $aiMessage = $this->postDraftToSupergroup($aiBotApi, $botUser, $sourceText, $translatedText, $targetLocale, $aiBotToken, $groupId);
            } else {
                // Supergroup not configured: persist draft for admin panel only.
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
                    'source_hash' => hash('sha256', $sourceText),
                    'text_manager' => '',
                    'status' => AiMessage::STATUS_PENDING,
                ]);

                Log::channel('app')->info('SendAiDraftJob: draft created (no AI bot configured)', [
                    'source' => 'send_ai_draft_no_ai_bot',
                    'bot_user_id' => $botUser->id,
                    'platform' => $botUser->platform,
                ]);
            }

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
     * Post draft to the Telegram supergroup and persist the AiMessage with Telegram message_id.
     * The AiMessage is also visible in the admin panel workspace via the pending drafts list.
     *
     * @param AiBotApi $aiBotApi
     * @param BotUser  $botUser
     * @param string   $aiResponseText
     * @param string   $aiBotToken
     * @param string   $groupId
     *
     * @return AiMessage
     */
    private function postDraftToSupergroup(
        AiBotApi $aiBotApi,
        BotUser $botUser,
        string $aiResponseText,
        ?string $translatedText,
        ?string $targetLocale,
        string $aiBotToken,
        string $groupId,
    ): AiMessage {
        $draftText = $this->formatBilingualDraft($aiResponseText, $translatedText, $targetLocale);

        $response = $aiBotApi->send('sendMessage', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'text' => $draftText,
            'parse_mode' => 'html',
        ]);

        if ($response->ok !== true) {
            throw new \RuntimeException('Telegram API error sending draft: ' . json_encode((array) $response), 1);
        }

        $aiMessage = AiMessage::create([
            'bot_user_id' => $botUser->id,
            'message_id' => $response->message_id,
            'text_ai' => $aiResponseText,
            'text_source' => $aiResponseText,
            'text_translated' => $translatedText,
            'source_locale' => 'ru',
            'target_locale' => $targetLocale,
            'translation_provider' => $translatedText !== null ? 'translation_core' : null,
            'translation_status' => $translatedText !== null ? 'ready' : 'empty',
            'source_hash' => hash('sha256', trim($aiResponseText)),
            'text_manager' => '',
            'status' => AiMessage::STATUS_PENDING,
        ]);

        $aiBotApi->send('editMessageReplyMarkup', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'message_id' => $response->message_id,
            'reply_markup' => AiHelper::preparedAiReplyMarkup((int) $aiMessage->message_id, $aiResponseText),
        ]);

        return $aiMessage;
    }

    /**
     * @return array{string|null, string|null, string|null, string}
     */
    private function translateDraft(BotUser $botUser, string $sourceText): array
    {
        $targetLocale = $botUser->preferred_language_code;
        if ($targetLocale === null || $targetLocale === '' || $targetLocale === 'ru') {
            return [$targetLocale, $targetLocale === 'ru' ? $sourceText : null, 'same_locale', $targetLocale === 'ru' ? 'ready' : 'empty'];
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

    private function formatBilingualDraft(string $sourceText, ?string $translatedText, ?string $targetLocale): string
    {
        $targetLabel = $targetLocale !== null && $targetLocale !== '' ? strtoupper($targetLocale) : 'язык клиента не выбран';
        $translatedBlock = $translatedText !== null && $translatedText !== ''
            ? e($translatedText)
            : 'Перевод пока недоступен.';

        return "<b>🤖 ИИ-черновик</b>\n\n"
            . "<b>🇷🇺 Для оператора:</b>\n" . e($sourceText) . "\n\n"
            . "<b>🌐 Клиенту на {$targetLabel}:</b>\n" . $translatedBlock;
    }
}
