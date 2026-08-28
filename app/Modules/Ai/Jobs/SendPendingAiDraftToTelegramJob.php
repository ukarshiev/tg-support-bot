<?php

namespace App\Modules\Ai\Jobs;

use App\Helpers\AiHelper;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\Services\AiBotApi;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Доставляет в Telegram черновик, который был готов раньше forum-темы клиента. */
class SendPendingAiDraftToTelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 6;

    public array $backoff = [2, 5, 10, 30, 60, 120];

    public int $timeout = 20;

    public function __construct(public readonly int $aiMessageId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiBotApi $aiBotApi): void
    {
        $draft = AiMessage::find($this->aiMessageId);
        if (
            $draft === null
            || $draft->status !== AiMessage::STATUS_PENDING
            || $draft->message_id !== null
        ) {
            return;
        }

        $botUser = BotUser::find($draft->bot_user_id);
        if ($botUser === null) {
            throw new \RuntimeException('BotUser not found for AI draft: ' . $draft->id, 1);
        }

        $token = (string) app(SettingsService::class)->get('telegram_ai.token');
        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');
        if ($token === '' || $groupId === '') {
            Log::channel('app')->warning('Pending AI draft cannot be delivered: Telegram AI bot is not configured', [
                'source' => 'send_pending_ai_draft_not_configured',
                'ai_message_id' => $draft->id,
                'bot_user_id' => $botUser->id,
            ]);

            return;
        }

        if (empty($botUser->topic_id)) {
            TopicCreateJob::dispatch($botUser->id);
            $this->release(3);

            return;
        }

        $sourceText = (string) ($draft->text_source ?: $draft->text_ai);
        $draftText = $this->formatDraft($sourceText, $draft->text_translated, $draft->target_locale);
        $response = $aiBotApi->send('sendMessage', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'text' => $draftText,
            'parse_mode' => 'html',
        ]);

        if ($response->ok !== true) {
            throw new \RuntimeException('Telegram API error sending pending draft: ' . json_encode((array) $response), 1);
        }

        $draft->update(['message_id' => $response->message_id]);

        $aiBotApi->send('editMessageReplyMarkup', [
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'message_id' => $response->message_id,
            'reply_markup' => AiHelper::preparedAiReplyMarkup((int) $response->message_id, $sourceText),
        ]);

        Log::channel('app')->info('Pending AI draft delivered after Telegram topic became available', [
            'source' => 'send_pending_ai_draft_delivered',
            'ai_message_id' => $draft->id,
            'bot_user_id' => $botUser->id,
            'topic_id' => $botUser->topic_id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('app')->error('Pending AI draft delivery permanently failed', [
            'source' => 'send_pending_ai_draft_failed',
            'ai_message_id' => $this->aiMessageId,
            'error_class' => $exception::class,
        ]);
    }

    private function formatDraft(string $sourceText, ?string $translatedText, ?string $targetLocale): string
    {
        if ($targetLocale === null || $targetLocale === '' || $targetLocale === 'ru') {
            return "<b>🤖 ИИ-черновик</b>\n\n"
                . "<b>Оригинал без перевода:</b>\n" . e($sourceText);
        }

        $translatedBlock = $translatedText !== null && $translatedText !== ''
            ? e($translatedText)
            : 'Перевод пока недоступен.';

        return "<b>🤖 ИИ-черновик</b>\n\n"
            . "<b>🇷🇺 Для оператора:</b>\n" . e($sourceText) . "\n\n"
            . '<b>🌐 Клиенту на ' . strtoupper($targetLocale) . ":</b>\n" . $translatedBlock;
    }
}
