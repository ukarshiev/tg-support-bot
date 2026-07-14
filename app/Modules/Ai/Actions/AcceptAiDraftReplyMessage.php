<?php

namespace App\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class AcceptAiDraftReplyMessage
{
    public function handle(TelegramUpdateDto $update, ?BotUser $botUser): bool
    {
        if ($botUser === null || $update->typeSource !== 'supergroup') {
            return false;
        }

        $replyMessageId = $update->replyToMessage['message_id'] ?? null;
        if (! is_numeric($replyMessageId)) {
            return false;
        }

        $draft = AiMessage::where('message_id', (int) $replyMessageId)->first();
        if ($draft === null) {
            return false;
        }

        if ((int) $draft->bot_user_id !== (int) $botUser->id) {
            Log::channel('app')->warning('AcceptAiDraftReplyMessage: bot user mismatch', [
                'source' => 'ai_draft_reply_mismatch',
                'ai_message_id' => $draft->id,
                'draft_bot_user_id' => $draft->bot_user_id,
                'topic_bot_user_id' => $botUser->id,
            ]);

            $this->sendThreadNotice($update, 'AI-подсказка относится к другому диалогу.');
            return true;
        }

        if ($draft->status !== AiMessage::STATUS_PENDING) {
            $this->sendThreadNotice($update, 'AI-подсказка уже обработана.');
            return true;
        }

        $editedText = trim((string) ($update->text ?? ''));
        if ($editedText === '') {
            $this->sendThreadNotice($update, 'Для изменения AI-подсказки отправьте текстовым reply.');
            return true;
        }

        [$translated, $provider, $translationStatus] = $this->translateForClient($botUser, $editedText);

        $draft->update([
            'text_ai' => $editedText,
            'text_source' => $editedText,
            'text_translated' => $translated,
            'source_locale' => 'ru',
            'target_locale' => $botUser->preferred_language_code,
            'translation_provider' => $provider,
            'translation_status' => $translationStatus,
            'source_hash' => hash('sha256', trim($editedText)),
            'status' => 'delivery_pending',
        ]);

        DeliverAiMessageJob::dispatch($draft->id, $update, true, true);

        Log::channel('app')->info('AcceptAiDraftReplyMessage: edited draft queued for delivery', [
            'source' => 'ai_draft_reply_delivery_queued',
            'ai_message_id' => $draft->id,
            'bot_user_id' => $botUser->id,
            'reply_message_id' => $update->messageId,
        ]);

        return true;
    }

    /** @return array{string, string, string} */
    private function translateForClient(BotUser $botUser, string $sourceText): array
    {
        $locale = strtolower(trim((string) $botUser->preferred_language_code));
        if ($locale === 'ru') {
            return [$sourceText, 'same_locale', 'ready'];
        }

        if ($locale !== '') {
            $translation = app(TranslationService::class)->translate(new TranslationRequest(
                sourceLocale: 'ru',
                targetLocale: $locale,
                text: $sourceText,
                purpose: 'ai_operator_edited_reply',
            ));

            if ($translation->success && trim((string) $translation->text) !== '') {
                return [$translation->text, $translation->provider, 'ready'];
            }
        }

        return [
            'A support agent will reply shortly. We could not prepare a safe localized answer.',
            'builtin_safe_english',
            'ready',
        ];
    }

    private function sendThreadNotice(TelegramUpdateDto $update, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'typeSource' => 'supergroup',
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $update->messageThreadId,
            'text' => $text,
            'parse_mode' => null,
        ]));
    }
}
