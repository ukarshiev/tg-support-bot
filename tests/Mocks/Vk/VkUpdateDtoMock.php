<?php

namespace Tests\Mocks\Vk;

use App\Modules\Vk\DTOs\VkUpdateDto;
use Illuminate\Support\Facades\Request;
use Tests\Mocks\PayloadIdentifier;

class VkUpdateDtoMock
{
    /**
     * @return array
     */
    public static function getDtoParams(): array
    {
        $timestamp = time();
        $messageIdentifier = PayloadIdentifier::next();

        return [
            'group_id' => 123_456_789,
            'type' => 'message_new',
            'event_id' => 'test-event-' . $messageIdentifier,
            'v' => '5.199',
            'object' => [
                'client_info' => [
                    'button_actions' => [
                        'text',
                        'vkpay',
                        'open_app',
                        'location',
                        'open_link',
                        'open_photo',
                        'callback',
                        'intent_subscribe',
                        'intent_unsubscribe',
                    ],
                    'keyboard' => true,
                    'inline_keyboard' => true,
                    'carousel' => true,
                    'lang_id' => 0,
                ],
                'message' => [
                    'date' => $timestamp,
                    'from_id' => 1_424_646_511,
                    'id' => $messageIdentifier,
                    'version' => 1,
                    'out' => 0,
                    'fwd_messages' => [],
                    'important' => false,
                    'is_hidden' => false,
                    'attachments' => [],
                    'conversation_message_id' => $messageIdentifier,
                    'text' => 'Test text',
                    'peer_id' => 1_424_646_511,
                    'random_id' => 0,
                ],
            ],
            'secret' => 'test-secret',
        ];
    }

    /**
     * @param array $dtoParams
     *
     * @return VkUpdateDto
     */
    public static function getDto(array $dtoParams = []): VkUpdateDto
    {
        if (empty($dtoParams)) {
            $dtoParams = self::getDtoParams();
        }

        $request = Request::create('api/telegram/bot', 'POST', $dtoParams);
        return VkUpdateDto::fromRequest($request);
    }
}
