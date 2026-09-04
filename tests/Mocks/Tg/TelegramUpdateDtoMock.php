<?php

namespace Tests\Mocks\Tg;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class TelegramUpdateDtoMock extends TelegramUpdateDto
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
        $userIdentifier = $botUser?->chat_id ?? 1_424_646_511;

        return [
            'update_id' => $messageIdentifier,
            'message' => [
                'message_id' => $messageIdentifier,
                'from' => [
                    'id' => $userIdentifier,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => $userIdentifier,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                    'type' => 'private',
                ],
                'date' => $timestamp,
                'text' => 'Тестовое сообщение',
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
