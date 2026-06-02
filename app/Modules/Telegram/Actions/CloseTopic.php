<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class CloseTopic
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if ($botUser->isClosed()) {
            return;
        }

        $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

        switch ($botUser->platform) {
            case 'telegram':
                $this->sendMessageInTelegram($botUser);
                break;

            case 'vk':
                $this->sendMessageInVk($botUser);
                break;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'editForumTopic',
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
            'icon_custom_emoji_id' => __('icons.outgoing'),
        ]));

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'closeForumTopic',
            'chat_id' => $groupId,
            'message_thread_id' => $botUser->topic_id,
        ]));

        $botUser->update([
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        try {
            app(SendFeedbackForm::class)->execute($botUser);
        } catch (\Throwable $e) {
            Log::channel('loki')->error('CloseTopic: feedback form delivery failed, topic close completed regardless', [
                'source' => 'close_topic_feedback_failed',
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendMessageInTelegram(BotUser $botUser): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => __('messages.message_close_topic'),
            'parse_mode' => 'html',
        ]));
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendMessageInVk(BotUser $botUser): void
    {
        SendVkSimpleMessageJob::dispatch(
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => __('messages.message_close_topic'),
            ]),
        );
    }
}
