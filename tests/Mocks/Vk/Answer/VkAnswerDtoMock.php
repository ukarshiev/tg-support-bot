<?php

namespace Tests\Mocks\Vk\Answer;

use App\Modules\Vk\DTOs\VkAnswerDto;
use Tests\Mocks\PayloadIdentifier;

class VkAnswerDtoMock
{
    /**
     * @return array
     */
    public static function getDtoParams(): array
    {
        return [
            'response_code' => 200,
            'response' => PayloadIdentifier::next(),
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return VkAnswerDto
     */
    public static function getDto(array $dtoParams = []): VkAnswerDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        return VkAnswerDto::fromData($dtoParams);
    }
}
