<?php

namespace Tests\Mocks\Max\Answer;

use App\Modules\Max\DTOs\MaxAnswerDto;
use Tests\Mocks\PayloadIdentifier;

class MaxAnswerDtoMock
{
    /**
     * @return array
     */
    public static function getDtoParams(): array
    {
        return [
            'response_code' => 200,
            'response' => 'msg-' . PayloadIdentifier::next(),
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return MaxAnswerDto
     */
    public static function getDto(array $dtoParams = []): MaxAnswerDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        return MaxAnswerDto::fromData($dtoParams);
    }
}
