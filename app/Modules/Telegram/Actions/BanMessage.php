<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Jobs\SendTelegramTopicMessageJob;

class BanMessage
{
    /**
     * Send message indicating that user has blocked the bot.
     *
     * @param int   $botUserId
     * @param mixed $update
     *
     * @return void
     */
    public function execute(int $botUserId, mixed $update): void
    {
        $botUser = BotUser::find($botUserId);

        if ($botUser === null) {
            throw new \RuntimeException("Telegram bot user {$botUserId} was not found");
        }

        SendTelegramTopicMessageJob::dispatch($botUser->id, __('messages.ban_bot'));
    }
}
