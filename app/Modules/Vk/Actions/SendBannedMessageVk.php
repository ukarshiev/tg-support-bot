<?php

namespace App\Modules\Vk\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;
use App\Services\AutoReplies\SystemAutoReplyResolver;
use Illuminate\Support\Facades\Log;

class SendBannedMessageVk
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

        SendVkSimpleMessageJob::dispatch(
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => $text,
            ]),
        );

        Log::channel('app')->info('SendBannedMessageVk: localized notice dispatched', [
            'source' => 'ban_notice_dispatched',
            'bot_user_id' => $botUser->id,
            'platform' => $botUser->platform,
            'locale' => $botUser->preferred_language_code,
            'auto_reply_type' => AutoReply::TYPE_BAN,
        ]);
    }
}
