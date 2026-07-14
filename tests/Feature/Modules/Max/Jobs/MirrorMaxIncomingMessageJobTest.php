<?php

namespace Tests\Feature\Modules\Max\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Max\Jobs\MirrorMaxIncomingMessageJob;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MirrorMaxIncomingMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_resumes_from_failed_attachment_without_resending_delivered_one(): void
    {
        Queue::fake();
        Http::fake([
            'https://cdn.example/*' => Http::response('file-content', 200),
        ]);
        $botUser = BotUser::getUserByChatId(20202, 'max');
        $botUser->update(['topic_id' => 502]);
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'max',
            'message_type' => 'incoming',
            'source_event_key' => Message::sourceEventKey('max', $botUser->id, 'mid-exact-1'),
            'from_id' => 8801,
            'to_id' => 0,
            'text' => 'Two files',
        ]);
        $message->attachments()->createMany([
            ['file_id' => 'https://cdn.example/one.jpg', 'file_type' => 'photo'],
            ['file_id' => 'https://cdn.example/two.pdf', 'file_type' => 'document'],
        ]);

        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->times(3)->andReturn(
            new TelegramAnswerDto(ok: true, message_id: 9001, response_code: 200),
            new TelegramAnswerDto(ok: false, response_code: 503, type_error: 'UPSTREAM_UNAVAILABLE'),
            new TelegramAnswerDto(ok: true, message_id: 9002, response_code: 200),
        );
        $job = new MirrorMaxIncomingMessageJob(
            $botUser->id,
            $message->id,
            'max-event-1',
            'mid-exact-1',
            $telegram,
        );

        try {
            $job->handle();
            $this->fail('The first attempt must surface a transient failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('messages', ['id' => $message->id]);
        }

        $job->handle();

        $this->assertSame(2, DeliveryOperation::where('message_id', $message->id)->count());
        $this->assertSame(
            2,
            DeliveryOperation::where('message_id', $message->id)
                ->where('status', DeliveryOperation::STATUS_DELIVERED)
                ->count(),
        );
    }
}
