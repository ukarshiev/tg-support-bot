<?php

namespace Tests\Mocks\Tg\Answer;

use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Tests\Mocks\PayloadIdentifier;

class TelegramAnswerDtoMock extends TelegramUpdateDto
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

        return [
            'ok' => true,
            'result' => [
                'message_id' => $messageIdentifier,
                'from' => [
                    'id' => 9_000_000_003,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'last_name' => 'Testov',
                    'username' => 'usertest',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => $botUser?->chat_id ?? 1_424_646_511,
                    'first_name' => 'Test 2',
                    'last_name' => 'Testov 2',
                    'username' => 'usertest_2',
                    'type' => 'private',
                ],
                'date' => $timestamp,
                'text' => 'Тестовое сообщение',
            ],
            'response_code' => 200,
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return TelegramAnswerDto|null
     */
    public static function getDto(array $dtoParams = []): ?TelegramAnswerDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        return TelegramAnswerDto::fromData($dtoParams);
    }
}
