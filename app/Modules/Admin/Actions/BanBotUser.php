<?php

namespace App\Modules\Admin\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;

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
}
