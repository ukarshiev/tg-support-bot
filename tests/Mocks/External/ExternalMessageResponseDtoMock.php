<?php

namespace Tests\Mocks\External;

use App\Modules\External\DTOs\ExternalMessageResponseDto;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Support\Facades\Request;

class ExternalMessageResponseDtoMock extends TelegramUpdateDto
{
    public static function getDtoParams(): array
    {
        $timestamp = time();
        return [
            'message_type' => 'outgoing',
            'to_id' => 1_424_646_511,
            'from_id' => 9_000_000_001,
            'text' => 'Тестовое сообщение',
            'date' => date('d.m.Y H:i:s', $timestamp),
            'content_type' => 'text' ,
            'file_id' => null,
            'file_url' => null,
            'file_type' => null,
        ];
    }

    public static function getDto(array $dtoParams = []): ExternalMessageResponseDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return ExternalMessageResponseDto::from($request);
    }
}
