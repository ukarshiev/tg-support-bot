<?php

namespace App\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Ai\Jobs\DeliverAiMessageJob;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Exception;

class AiAcceptMessage extends AiAction
{
    /**
     * Confirm AI response sending — Telegram callback entry point.
     *
     * Resolves the AiMessage from callback_data and delegates to executeForDraft().
     *
     * @param TelegramUpdateDto $update
     *
     * @return void
     */
    public function execute(TelegramUpdateDto $update): void
    {
        try {
            if (empty((string) app(SettingsService::class)->get('telegram_ai.token'))) {
                throw new Exception('AI bot token not specified!', 1);
            }

            $botUser = BotUser::getOrCreateByTelegramUpdate($update);
            if (!$botUser) {
                throw new Exception('User not found', 1);
            }

            $messageData = $this->getMessageDataByCallbackData($update->callbackData);
            if (empty($messageData)) {
                throw new Exception('Message not found in database!', 1);
            }

            if ($messageData->status === AiMessage::STATUS_ACCEPTED || $messageData->status === 'delivery_pending') {
                return;
            }

            $messageData->update(['status' => 'delivery_pending']);

            Log::channel('app')->info('AiAcceptMessage: AI answer queued for delivery', [
                'source' => 'ai_accept_delivery_queued',
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'ai_message_id' => $messageData->id,
            ]);

            DeliverAiMessageJob::dispatch($messageData->id, $update, true, true);
        } catch (\Throwable $e) {
            Log::channel('app')->error($e->getMessage(), ['source' => 'ai_error']);
        }
    }

    /**
     * Accept an AI draft directly — used by the admin panel workspace.
     *
     * Sets status=accepted, delivers to the user. When a supergroup message_id
     * exists (the AI bot is configured), deletes the draft message from the
     * supergroup so the thread stays clean.
     *
     * @param AiMessage $aiMessage The pending draft to accept
     *
     * @return void
     */
    public function executeForDraft(AiMessage $aiMessage): void
    {
        /** @var BotUser|null $botUser */
        $botUser = $aiMessage->botUser;
        if ($botUser === null) {
            Log::channel('app')->warning('AiAcceptMessage::executeForDraft: botUser not found', [
                'source' => 'ai_error',
                'ai_message_id' => $aiMessage->id,
            ]);

            return;
        }

        $textAi = (string) ($aiMessage->text_translated ?: $aiMessage->text_ai);
        if ($textAi === '') {
            Log::channel('app')->warning('AiAcceptMessage::executeForDraft: empty text_ai', [
                'source' => 'ai_error',
                'ai_message_id' => $aiMessage->id,
            ]);

            return;
        }

        if ($aiMessage->status === AiMessage::STATUS_ACCEPTED || $aiMessage->status === 'delivery_pending') {
            return;
        }

        $aiMessage->update(['status' => 'delivery_pending']);

        Log::channel('app')->info('AiAcceptMessage::executeForDraft: queued for delivery', [
            'source' => 'ai_accept_draft_delivery_queued',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'ai_message_id' => $aiMessage->id,
        ]);

        DeliverAiMessageJob::dispatch($aiMessage->id, null, true, true);
    }
}
