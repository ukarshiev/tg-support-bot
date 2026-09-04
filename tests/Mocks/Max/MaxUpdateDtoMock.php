<?php

namespace Tests\Mocks\Max;

use App\Modules\Max\DTOs\MaxUpdateDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class MaxUpdateDtoMock
{
    /**
     * @return array
     */
    public static function getDtoParams(): array
    {
        $timestamp = time();
        $messageIdentifier = PayloadIdentifier::next();

        return [
            'update_type' => 'message_created',
            'timestamp' => $timestamp * 1000,
            'message' => [
                'sender' => [
                    'user_id' => 1_424_646_511,
                    'name' => 'Test User',
                ],
                'recipient' => [
                    'user_id' => 9_000_000_001,
                ],
                'timestamp' => $timestamp * 1000,
                'body' => [
                    'mid' => 'msg-' . $messageIdentifier,
                    'seq' => $messageIdentifier,
                    'text' => 'Test text',
                    'attachments' => [],
                ],
            ],
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return MaxUpdateDto
     */
    public static function getDto(array $dtoParams = []): MaxUpdateDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/max/bot', 'POST', $dtoParams);
        return MaxUpdateDto::fromRequest($request);
    }
}
