<?php

namespace App\Modules\Admin\Actions;

use App\Models\BotUser;

class UnbanBotUser
{
    /**
     * Lift a ban from the admin workspace.
     *
     * Clears `is_banned`/`banned_at` so the user's messages are accepted again.
     * Does NOT change `is_closed` — the conversation stays closed until a new
     * message re-opens it (see SendReplyAction).
     *
     * No-op when the user is not banned.
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        if (! $botUser->isBanned()) {
            return;
        }

        $botUser->update([
            'is_banned' => false,
            'banned_at' => null,
        ]);
    }
}
