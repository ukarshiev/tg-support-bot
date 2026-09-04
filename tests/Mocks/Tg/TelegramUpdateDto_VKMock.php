<?php

namespace Tests\Mocks\Tg;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class TelegramUpdateDto_VKMock extends TelegramUpdateDto
{
    /**
     * @param BotUser|null $botUser
     *
     * @return array
     */
    public static function getDtoParams(?BotUser $botUser = null): array
    {
        $timestamp = time();
        $messageIdentifier = PayloadIdentifier::next();

        if (!$botUser) {
            $botUser = BotUser::getUserByChatId(1_424_646_511, 'vk');
        }

        return [
            'update_id' => $messageIdentifier,
            'message' => [
                'message_id' => $messageIdentifier,
                'from' => [
                    'id' => 9_000_000_001,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => -100_000_000_000,
                    'title' => 'Prog-Time | Чаты',
                    'is_forum' => true,
                    'type' => 'supergroup',
                ],
                'date' => $timestamp,
                'message_thread_id' => $botUser->topic_id,
                'text' => 'Тестовое сообщение',
                'is_topic_message' => true,
            ],
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return TelegramUpdateDto
     */
    public static function getDto(array $dtoParams = []): TelegramUpdateDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return TelegramUpdateDto::fromRequest($request);
    }
}
