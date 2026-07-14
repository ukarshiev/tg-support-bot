<?php

namespace Tests\Unit\Modules\Feedback\Actions;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Feedback;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use App\Modules\Feedback\Jobs\DeliverFeedbackFormJob;
use App\Modules\Max\Api\MaxMethods;
use App\Modules\Max\DTOs\MaxAnswerDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendFeedbackFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_creates_pending_delivery_and_queues_confirmed_delivery_job(): void
    {
        $botUser = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        $feedback = Feedback::where('bot_user_id', $botUser->id)->firstOrFail();
        $this->assertSame('delivery_pending', $feedback->status);
        $this->assertNotNull($feedback->closed_at);
        Queue::assertPushed(DeliverFeedbackFormJob::class, fn ($job): bool => $job->feedbackId === $feedback->id);
    }

    public function test_new_form_supersedes_every_old_active_form(): void
    {
        $botUser = BotUser::create(['chat_id' => 1002, 'platform' => 'telegram']);
        $old = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'awaiting_rating']);
        $pending = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'delivery_pending']);

        (new SendFeedbackForm())->execute($botUser);

        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame('superseded', $pending->fresh()->status);
        $this->assertSame(1, Feedback::where('bot_user_id', $botUser->id)->where('status', 'delivery_pending')->count());
    }

    public function test_successful_api_response_is_required_before_awaiting_rating(): void
    {
        $botUser = BotUser::create(['chat_id' => 1003, 'platform' => 'telegram']);
        $feedback = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'delivery_pending']);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 55, 'chat' => ['id' => 1003]],
        ])]);

        (new DeliverFeedbackFormJob($feedback->id, 'Please rate support'))->handle();

        $this->assertSame('awaiting_rating', $feedback->fresh()->status);
        $this->assertDatabaseHas('delivery_operations', [
            'bot_user_id' => $botUser->id,
            'operation' => 'feedback-form',
            'status' => DeliveryOperation::STATUS_DELIVERED,
        ]);
    }

    public function test_terminal_delivery_failure_never_leaves_awaiting_rating(): void
    {
        $botUser = BotUser::create(['chat_id' => 1004, 'platform' => 'unknown']);
        $feedback = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'delivery_pending']);
        $job = new DeliverFeedbackFormJob($feedback->id, 'Rate support');

        try {
            $job->handle();
            $this->fail('Delivery exception expected');
        } catch (\RuntimeException $exception) {
            $job->failed($exception);
        }

        $this->assertSame('delivery_failed', $feedback->fresh()->status);
        $this->assertDatabaseHas('delivery_operations', [
            'operation' => 'feedback-form',
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
    }

    public function test_vk_form_is_confirmed_with_localized_keyboard(): void
    {
        $botUser = BotUser::create(['chat_id' => 2001, 'platform' => 'vk']);
        $feedback = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'delivery_pending']);
        Http::fake(['*' => Http::response(['response' => 991])]);

        (new DeliverFeedbackFormJob($feedback->id, 'Rate support'))->handle();

        $this->assertSame('awaiting_rating', $feedback->fresh()->status);
        Http::assertSent(function ($request) use ($feedback, $botUser): bool {
            $keyboard = json_decode((string) ($request->data()['keyboard'] ?? ''), true);
            $payload = $keyboard['buttons'][0][4]['action']['payload'] ?? '';

            return str_contains((string) $payload, "feedback_rate_{$botUser->id}_{$feedback->id}_5");
        });
    }

    public function test_max_form_is_confirmed_with_rating_keyboard(): void
    {
        $botUser = BotUser::create(['chat_id' => 3001, 'platform' => 'max']);
        $feedback = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'delivery_pending']);
        $max = $this->createMock(MaxMethods::class);
        $max->expects($this->once())
            ->method('sendQuery')
            ->with('sendMessage', $this->callback(function (array $params) use ($feedback, $botUser): bool {
                return ($params['keyboard'][0][4]['payload'] ?? null)
                    === "feedback_rate_{$botUser->id}_{$feedback->id}_5";
            }))
            ->willReturn(MaxAnswerDto::fromData(['response_code' => 200, 'response' => 'max-44']));
        $this->app->instance(MaxMethods::class, $max);

        (new DeliverFeedbackFormJob($feedback->id, 'Rate support'))->handle();

        $this->assertSame('awaiting_rating', $feedback->fresh()->status);
    }

    public function test_disabled_prompt_supersedes_old_form_without_creating_new_one(): void
    {
        AutoReply::query()->where('type', AutoReply::TYPE_FEEDBACK_REQUEST)->update(['enabled' => false]);
        $botUser = BotUser::create(['chat_id' => 1005, 'platform' => 'telegram']);
        $old = Feedback::create(['bot_user_id' => $botUser->id, 'status' => 'awaiting_rating']);

        (new SendFeedbackForm())->execute($botUser);

        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertDatabaseCount('feedbacks', 1);
        Queue::assertNothingPushed();
    }
}
