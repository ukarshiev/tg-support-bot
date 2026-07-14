<?php

namespace Tests\Feature\Modules\Vk\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Vk\Jobs\MirrorVkIncomingMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MirrorVkIncomingMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_telegram_failure_does_not_remove_saved_vk_message(): void
    {
        Queue::fake();
        $botUser = BotUser::getUserByChatId(10101, 'vk');
        $botUser->update(['topic_id' => 501]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'vk',
            'message_type' => 'incoming',
            'source_event_key' => Message::sourceEventKey('vk', $botUser->id, 7001),
            'from_id' => 7001,
            'to_id' => 0,
            'text' => 'Saved first',
        ]);
        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->once()->andReturn(
            new TelegramAnswerDto(ok: false, response_code: 503, type_error: 'UPSTREAM_UNAVAILABLE'),
        );

        try {
            (new MirrorVkIncomingMessageJob($botUser->id, $message->id, 'vk-event-1', null, $telegram))->handle();
            $this->fail('Transient Telegram failure must be retried by the queue.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('messages', ['id' => $message->id, 'text' => 'Saved first']);
            $this->assertDatabaseHas('delivery_operations', [
                'message_id' => $message->id,
                'status' => DeliveryOperation::STATUS_RETRYING,
            ]);
        }
    }
}
