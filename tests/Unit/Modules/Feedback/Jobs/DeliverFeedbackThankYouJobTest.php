<?php

namespace Tests\Unit\Modules\Feedback\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Feedback;
use App\Modules\Feedback\Jobs\DeliverFeedbackThankYouJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverFeedbackThankYouJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_thank_you_delivery_is_tracked_without_changing_saved_rating(): void
    {
        app(SettingsService::class)->set('telegram.token', 'main-token');
        $botUser = BotUser::create(['chat_id' => 901, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'rating' => 5,
            'status' => 'completed_no_comment',
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 44, 'chat' => ['id' => 901]],
        ])]);

        (new DeliverFeedbackThankYouJob($feedback->id, 'Thank you', 44, 901))->handle();

        $this->assertSame(5, $feedback->fresh()->rating);
        $this->assertSame('completed_no_comment', $feedback->fresh()->status);
        $this->assertDatabaseHas('delivery_operations', [
            'operation' => 'feedback-thank-you',
            'status' => DeliveryOperation::STATUS_DELIVERED,
        ]);
    }

    public function test_terminal_thank_you_failure_preserves_rating_and_is_observable(): void
    {
        $botUser = BotUser::create(['chat_id' => 902, 'platform' => 'unknown']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'rating' => 4,
            'status' => 'completed_no_comment',
        ]);
        $job = new DeliverFeedbackThankYouJob($feedback->id, 'Thank you');

        try {
            $job->handle();
            $this->fail('Delivery exception expected');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame(4, $feedback->fresh()->rating);
        $this->assertSame('completed_no_comment', $feedback->fresh()->status);
        $this->assertDatabaseHas('delivery_operations', [
            'operation' => 'feedback-thank-you',
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
    }
}
