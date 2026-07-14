<?php

namespace Tests\Feature\Modules\Max\Jobs;

use App\Models\BotUser;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Max\DTOs\MaxTextMessageDto;
use App\Modules\Max\Jobs\SendMaxMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Mocks\Max\Answer\MaxAnswerDtoMock;
use Tests\Mocks\Tg\TelegramUpdateDto_VKMock;
use Tests\TestCase;

class SendMaxMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_outgoing_message_is_saved(): void
    {
        Queue::fake();
        $botUser = BotUser::getUserByChatId(30303, 'max');
        $max = \Mockery::mock(MaxMethods::class);
        $max->shouldReceive('sendQuery')->once()->andReturn(MaxAnswerDtoMock::getDto());
        $job = new SendMaxMessageJob(
            $botUser->id,
            TelegramUpdateDto_VKMock::getDto(),
            MaxTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'user_id' => $botUser->chat_id,
                'text' => 'Hello',
            ]),
            $max,
        );

        $job->handle();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Hello',
        ]);
    }

    public function test_attachment_not_ready_is_retried_by_queue_without_sleeping_in_worker(): void
    {
        $botUser = BotUser::getUserByChatId(30304, 'max');
        $max = \Mockery::mock(MaxMethods::class);
        $max->shouldReceive('sendQuery')->once()->andReturn(MaxAnswerDtoMock::getDto([
            'response_code' => 500,
            'error_message' => 'attachment.not.ready',
        ]));
        $job = new SendMaxMessageJob(
            $botUser->id,
            TelegramUpdateDto_VKMock::getDto(),
            MaxTextMessageDto::from([
                'methodQuery' => 'sendFile',
                'user_id' => $botUser->chat_id,
                'file_token' => 'token',
            ]),
            $max,
        );

        $this->expectException(RuntimeException::class);
        $job->handle();
    }
}
