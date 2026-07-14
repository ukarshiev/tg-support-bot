<?php

namespace App\Modules\Ai\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Exception;

class AiCancelMessage extends AiAction
{
    /**
     * Cancel AI request — Telegram callback entry point.
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

            if ($messageData->message_id !== null) {
                SendTelegramSimpleQueryJob::dispatch(
                    TGTextMessageDto::from([
                        'token' => (string) app(SettingsService::class)->get('telegram_ai.token'),
                        'methodQuery' => 'deleteMessage',
                        'typeSource' => 'private',
                        'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
                        'message_thread_id' => $update->messageThreadId,
                        'message_id' => $messageData->message_id,
                    ]),
                );
            }

            $messageData->update(['status' => AiMessage::STATUS_CANCELLED]);
        } catch (\Throwable $e) {
            Log::channel('app')->error($e->getMessage(), ['source' => 'ai_error']);
        }
    }

    /**
     * Cancel an AI draft directly — used by the admin panel workspace.
     *
     * Sets status=cancelled. When a supergroup message_id exists (the AI bot
     * is configured), also deletes the draft message from the supergroup.
     *
     * @param AiMessage $aiMessage The pending draft to cancel
     *
     * @return void
     */
    public function executeForDraft(AiMessage $aiMessage): void
    {
        // Delete supergroup draft when it was posted there.
        if ($aiMessage->message_id !== null) {
            /** @var BotUser|null $botUser */
            $botUser = $aiMessage->botUser;
            $token = (string) app(SettingsService::class)->get('telegram_ai.token');
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

            if ($botUser !== null && $token !== '' && $groupId !== '') {
                SendTelegramSimpleQueryJob::dispatch(
                    TGTextMessageDto::from([
                        'token' => $token,
                        'methodQuery' => 'deleteMessage',
                        'typeSource' => 'supergroup',
                        'chat_id' => $groupId,
                        'message_id' => $aiMessage->message_id,
                        'message_thread_id' => $botUser->topic_id,
                    ]),
                );
            }
        }

        $aiMessage->update(['status' => AiMessage::STATUS_CANCELLED]);

        Log::channel('app')->info('AiCancelMessage::executeForDraft: cancelled', [
            'source' => 'ai_cancel_draft',
            'ai_message_id' => $aiMessage->id,
        ]);
    }
}
