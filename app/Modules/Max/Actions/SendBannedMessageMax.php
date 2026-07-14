<?php

namespace App\Modules\Max\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Max\Jobs\SendMaxSimpleMessageJob;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use Illuminate\Support\Facades\Log;

class SendBannedMessageMax
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

        SendMaxSimpleMessageJob::dispatch(
            MaxTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'user_id' => $botUser->chat_id,
                'text' => $text,
            ]),
        );

        Log::channel('app')->info('SendBannedMessageMax: localized notice dispatched', [
            'source' => 'ban_notice_dispatched',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'locale' => $botUser->preferred_language_code,
            'auto_reply_type' => AutoReply::TYPE_BAN,
        ]);
    }
}
