<?php

namespace App\Modules\Admin\Actions;

use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\ExternalMessage;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;

class ClearBotUserHistory
{
    /**
     * Delete a conversation's message history while keeping the chat itself.
     *
     * Removes, in a single transaction: the user's messages (and their
     * attachments / external-message rows) and AI messages. The BotUser record
     * and its feedback history are preserved — the dialog stays in the list,
     * just with an empty thread. Contrast with DeleteBotUser, which also removes
     * the BotUser.
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        DB::transaction(function () use ($botUser): void {
            $messageIds = Message::where('bot_user_id', $botUser->id)->pluck('id');

            if ($messageIds->isNotEmpty()) {
                MessageAttachment::whereIn('message_id', $messageIds)->delete();
                ExternalMessage::whereIn('message_id', $messageIds)->delete();
            }

            Message::where('bot_user_id', $botUser->id)->delete();
            AiMessage::where('bot_user_id', $botUser->id)->delete();
        });
    }
}
