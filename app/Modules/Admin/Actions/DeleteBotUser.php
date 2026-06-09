<?php

namespace App\Modules\Admin\Actions;

use App\Models\AiCondition;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\ExternalMessage;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\DB;

class DeleteBotUser
{
    /**
     * Permanently delete a bot user and everything tied to the conversation.
     *
     * Removes, in a single transaction: the user's messages (and their
     * attachments / external-message rows), AI messages, AI condition flags,
     * feedback records, and finally the BotUser itself. The database also
     * cascades most of these on `bot_users` delete, but the deletion is done
     * explicitly here so it is DB-agnostic (no reliance on SQLite FK pragmas)
     * and also clears `ai_conditions`, which has no cascade constraint.
     *
     * This does not touch the Telegram forum topic — it only removes local data.
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
            Feedback::where('bot_user_id', $botUser->id)->delete();
            AiCondition::where('bot_user_id', $botUser->id)->delete();

            $botUser->delete();
        });
    }
}
