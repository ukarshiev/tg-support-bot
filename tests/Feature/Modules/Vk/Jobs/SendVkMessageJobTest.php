<?php

namespace Tests\Feature\Modules\Vk\Jobs;

use App\Models\BotUser;
use App\Modules\Vk\Api\VkMethods;
use App\Modules\Vk\DTOs\VkTextMessageDto;
use App\Modules\Vk\Jobs\SendVkMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Mocks\Tg\TelegramUpdateDto_VKMock;
use Tests\Mocks\Vk\Answer\VkAnswerDtoMock;
use Tests\TestCase;

class SendVkMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_outgoing_message_is_saved(): void
    {
        Queue::fake();
        $botUser = BotUser::getUserByChatId(40404, 'vk');
        $vk = \Mockery::mock(VkMethods::class);
        $vk->shouldReceive('sendQueryVk')->once()->andReturn(VkAnswerDtoMock::getDto());
        $job = new SendVkMessageJob(
            $botUser->id,
            TelegramUpdateDto_VKMock::getDto(),
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => 'Hello',
            ]),
            $vk,
        );

        $job->handle();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Hello',
        ]);
    }

    public function test_transient_vk_error_is_not_swallowed(): void
    {
        $botUser = BotUser::getUserByChatId(40405, 'vk');
        $vk = \Mockery::mock(VkMethods::class);
        $vk->shouldReceive('sendQueryVk')->once()->andReturn(VkAnswerDtoMock::getDto([
            'response_code' => 503,
            'error_message' => 'upstream unavailable',
            'response' => 0,
        ]));
        $job = new SendVkMessageJob(
            $botUser->id,
            TelegramUpdateDto_VKMock::getDto(),
            VkTextMessageDto::from([
                'methodQuery' => 'messages.send',
                'peer_id' => $botUser->chat_id,
                'message' => 'Hello',
            ]),
            $vk,
        );

        $this->expectException(RuntimeException::class);
        $job->handle();
    }
}
