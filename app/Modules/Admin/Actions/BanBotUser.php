<?php

namespace App\Modules\Admin\Actions;

use App\Contracts\LocalizedSystemMessageChannel;
use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Max\Actions\SendBannedMessageMax;
use App\Modules\Telegram\Actions\SendBannedMessage;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Vk\Actions\SendBannedMessageVk;
use App\Platform\PlatformChannelRegistry;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Log;

class BanBotUser
{
    /**
     * Ban a bot user from the admin workspace.
     *
     * Marks the user as banned (`is_banned`/`banned_at`) and terminal
     * (`is_closed`/`closed_at`), and closes the user's Telegram forum topic
     * when present (telegram_group mode). No feedback form is sent.
     *
     * Future messages from a banned user are rejected by the platform webhook
     * controllers via `BotUser::isBanned()`.
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if ($botUser->isBanned()) {
            return;
        }

        $botUser->update([
            'is_banned' => true,
            'banned_at' => now(),
            'is_closed' => true,
            'closed_at' => $botUser->closed_at ?? now(),
        ]);

        $this->notifyClient($botUser->fresh());

        if ($botUser->platform === 'telegram' && !empty($botUser->topic_id)) {
            $groupId = (string) app(SettingsService::class)->get('telegram.group_id');

            if ($groupId !== '') {
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
        }
    }

    private function notifyClient(BotUser $botUser): void
    {
        match ($botUser->platform) {
            'telegram' => app(SendBannedMessage::class)->execute($botUser),
            'vk' => app(SendBannedMessageVk::class)->execute($botUser),
            'max' => app(SendBannedMessageMax::class)->execute($botUser),
            default => $this->notifyPrivateChannel($botUser),
        };
    }

    private function notifyPrivateChannel(BotUser $botUser): void
    {
        $channel = app(PlatformChannelRegistry::class)->for($botUser->platform);
        $text = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_BAN, $botUser);

        if ($text === null) {
            return;
        }

        if ($channel instanceof LocalizedSystemMessageChannel) {
            $channel->sendSystemMessage($botUser, AutoReply::TYPE_BAN, $text);

            return;
        }

        Log::channel('app')->warning('Private channel does not support localized ban notice', [
            'source' => 'localized_channel_capability_missing',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'auto_reply_type' => AutoReply::TYPE_BAN,
        ]);
    }
}
