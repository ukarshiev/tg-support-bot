<?php

namespace App\Modules\Telegram\Actions;

use App\Contracts\LocalizedSystemMessageChannel;
use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Max\Jobs\SendMaxSimpleMessageJob;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;
use App\Platform\PlatformChannelRegistry;
use App\Services\AutoReplies\SystemAutoReplyResolver;
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
        $closeText = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_DIALOG_CLOSED, $botUser);

        Log::channel('app')->info('CloseTopic: client message resolution completed', [
            'source' => 'close_topic_text_resolved',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'locale' => $botUser->preferred_language_code,
            'auto_reply_type' => AutoReply::TYPE_DIALOG_CLOSED,
            'delivery_enabled' => $closeText !== null,
        ]);

        if ($closeText !== null) {
            $this->sendCloseMessage($botUser, $closeText);
        }

        if ($groupId !== '' && !empty($botUser->topic_id)) {
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
        }

        $botUser->update([
            'is_closed' => true,
            'closed_at' => now(),
        ]);

        try {
            app(SendFeedbackForm::class)->execute($botUser);
        } catch (\Throwable $e) {
            Log::channel('app')->error('CloseTopic: feedback form delivery failed, topic close completed regardless', [
                'source' => 'close_topic_feedback_failed',
                'bot_user_id' => $botUser->id,
                'platform' => $botUser->platform,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendCloseMessage(BotUser $botUser, string $text): void
    {
        switch ($botUser->platform) {
            case 'telegram':
                $this->sendMessageInTelegram($botUser, $text);
                break;

            case 'vk':
                $this->sendMessageInVk($botUser, $text);
                break;

            case 'max':
                $this->sendMessageInMax($botUser, $text);
                break;

            default:
                $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);
                if ($channel instanceof LocalizedSystemMessageChannel) {
                    $channel->sendSystemMessage($botUser, AutoReply::TYPE_DIALOG_CLOSED, $text);
                }
                break;
        }
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendMessageInTelegram(BotUser $botUser, string $text): void
    {
        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $text,
            'parse_mode' => 'html',
        ]));
    }

    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    private function sendMessageInVk(BotUser $botUser, string $text): void
    {
        SendVkSimpleMessageJob::dispatch(
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => $text,
            ]),
        );
    }

    private function sendMessageInMax(BotUser $botUser, string $text): void
    {
        SendMaxSimpleMessageJob::dispatch(MaxTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'user_id' => $botUser->chat_id,
            'text' => $text,
        ]));
    }
}
