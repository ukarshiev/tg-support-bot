<?php

namespace Tests\Mocks\External;

use App\Modules\External\DTOs\ExternalMessageDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class ExternalMessageDtoMock
{
    /**
     * @return array
     */
    public static function getDtoParams(): array
    {
        $messageIdentifier = PayloadIdentifier::next();

        return [
            'source' => 'live_chat',
            'external_id' => '123456',
            'message_id' => $messageIdentifier,
            'text' => 'Тестовое сообщение',
            'uploaded_file' => null,
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return ExternalMessageDto
     */
    public static function getDto(array $dtoParams = []): ExternalMessageDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return ExternalMessageDto::fromRequest($request);
    }
}
