<?php

namespace Tests\Unit\Modules\Feedback\Actions;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Feedback\Actions\SendFeedbackForm;
use App\Modules\Max\Jobs\SendMaxMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendFeedbackFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BotUser::truncate();
        Feedback::truncate();
        Queue::fake();
    }

    public function test_creates_feedback_record_with_awaiting_rating_status(): void
    {
        $botUser = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        $this->assertDatabaseHas('feedbacks', [
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'rating' => null,
        ]);
    }

    public function test_dispatches_telegram_simple_query_job_for_telegram_user(): void
    {
        $botUser = BotUser::create(['chat_id' => 100001, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertEquals($botUser->chat_id, $job->queryParams->chat_id);
        $this->assertNotNull($job->queryParams->reply_markup);
    }

    public function test_telegram_keyboard_has_5_rating_buttons(): void
    {
        $botUser = BotUser::create(['chat_id' => 100002, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $markup = $job->queryParams->reply_markup;
        $this->assertIsArray($markup);
        $this->assertArrayHasKey('inline_keyboard', $markup);
        $this->assertCount(5, $markup['inline_keyboard'][0]);
    }

    public function test_telegram_callback_data_contains_bot_user_id_and_score(): void
    {
        $botUser = BotUser::create(['chat_id' => 100003, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        $feedback = Feedback::where('bot_user_id', $botUser->id)->first();

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $job = $pushed[0]['job'];
        $buttons = $job->queryParams->reply_markup['inline_keyboard'][0];

        foreach ($buttons as $index => $button) {
            $score = $index + 1;
            $this->assertEquals(
                "feedback_rate_{$botUser->id}_{$feedback->id}_{$score}",
                $button['callback_data']
            );
        }
    }

    public function test_dispatches_vk_simple_message_job_for_vk_user(): void
    {
        $botUser = BotUser::create(['chat_id' => 200001, 'platform' => 'vk']);

        (new SendFeedbackForm())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendVkSimpleMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('messages.send', $job->queryParams->methodQuery);
        $this->assertEquals($botUser->chat_id, $job->queryParams->peer_id);
        $this->assertNotNull($job->queryParams->keyboard);
    }

    public function test_dispatches_max_message_job_for_max_user(): void
    {
        $botUser = BotUser::create(['chat_id' => 300001, 'platform' => 'max']);

        (new SendFeedbackForm())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendMaxMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertEquals($botUser->chat_id, $job->queryParams->user_id);
        $this->assertNotNull($job->queryParams->keyboard);
    }

    public function test_dispatches_nothing_for_unsupported_platform(): void
    {
        $botUser = BotUser::create(['chat_id' => 999999, 'platform' => 'unknown']);

        (new SendFeedbackForm())->execute($botUser);

        Queue::assertNothingPushed();

        // Feedback record is still created even if delivery is skipped
        $this->assertDatabaseHas('feedbacks', [
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
        ]);
    }

    public function test_feedback_closed_at_is_set_on_creation(): void
    {
        $botUser = BotUser::create(['chat_id' => 100010, 'platform' => 'telegram']);

        (new SendFeedbackForm())->execute($botUser);

        $feedback = Feedback::where('bot_user_id', $botUser->id)->first();
        $this->assertNotNull($feedback->closed_at);
    }
}
