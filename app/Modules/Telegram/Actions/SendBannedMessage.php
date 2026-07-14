<?php

namespace App\Modules\Telegram\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use Illuminate\Support\Facades\Log;

class SendBannedMessage
{
    /**
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        $text = app(SystemAutoReplyResolver::class)->resolve(AutoReply::TYPE_BAN, $botUser);
        if ($text === null) {
            return;
        }

        SendTelegramSimpleQueryJob::dispatch(TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $botUser->chat_id,
            'text' => $text,
            'parse_mode' => 'html',
        ]));

        Log::channel('app')->info('SendBannedMessage: localized notice dispatched', [
            'source' => 'ban_notice_dispatched',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'locale' => $botUser->preferred_language_code,
            'auto_reply_type' => AutoReply::TYPE_BAN,
        ]);
    }
}
