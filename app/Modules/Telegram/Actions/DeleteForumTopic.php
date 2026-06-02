<?php

namespace App\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;

/**
 * Delete forum topic.
 */
class DeleteForumTopic
{
    /**
     * Delete forum topic.
     *
     * @param BotUser $botUser
     *
     * @return void
     */
    public function execute(BotUser $botUser): void
    {
        TelegramMethods::sendQueryTelegram('deleteForumTopic', [
            'chat_id' => (string) app(SettingsService::class)->get('telegram.group_id'),
            'message_thread_id' => $botUser->topic_id,
        ]);
    }
}
