<?php

namespace Tests\Unit\Modules\Feedback\Actions;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HandleFeedbackRatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BotUser::truncate();
        Feedback::truncate();
        Queue::fake();
    }

    public function test_saves_rating_and_transitions_status_to_completed_no_comment(): void
    {
        $botUser = BotUser::create(['chat_id' => 1001, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $callbackData = "feedback_rate_{$botUser->id}_{$feedback->id}_4";

        (new HandleFeedbackRating())->execute($callbackData);

        $feedback->refresh();
        $this->assertEquals(4, $feedback->rating);
        $this->assertEquals('completed_no_comment', $feedback->status);
    }

    public function test_dispatches_edit_message_when_message_id_and_chat_id_provided(): void
    {
        $botUser = BotUser::create(['chat_id' => 1002, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $callbackData = "feedback_rate_{$botUser->id}_{$feedback->id}_5";

        (new HandleFeedbackRating())->execute(
            callbackData: $callbackData,
            messageId: 9988,
            chatId: (int) $botUser->chat_id,
        );

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('editMessageText', $job->queryParams->methodQuery);
        $this->assertEquals(9988, $job->queryParams->message_id);
        $this->assertEquals($botUser->chat_id, $job->queryParams->chat_id);
    }

    public function test_does_not_dispatch_edit_message_when_message_id_is_null(): void
    {
        $botUser = BotUser::create(['chat_id' => 1003, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        $callbackData = "feedback_rate_{$botUser->id}_{$feedback->id}_3";

        (new HandleFeedbackRating())->execute(callbackData: $callbackData);

        Queue::assertNothingPushed();
    }

    public function test_does_nothing_for_invalid_callback_data(): void
    {
        (new HandleFeedbackRating())->execute('invalid_callback_data');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('feedbacks', 0);
    }

    public function test_does_nothing_when_feedback_record_not_found(): void
    {
        $botUser = BotUser::create(['chat_id' => 1004, 'platform' => 'telegram']);
        $callbackData = "feedback_rate_{$botUser->id}_99999_3";

        (new HandleFeedbackRating())->execute($callbackData);

        Queue::assertNothingPushed();
    }

    public function test_parse_callback_data_returns_correct_values(): void
    {
        $handler = new HandleFeedbackRating();

        $result = $handler->parseCallbackData('feedback_rate_42_100_3');

        $this->assertNotNull($result);
        $this->assertEquals(42, $result['botUserId']);
        $this->assertEquals(100, $result['feedbackId']);
        $this->assertEquals(3, $result['score']);
    }

    public function test_parse_callback_data_returns_null_for_invalid_format(): void
    {
        $handler = new HandleFeedbackRating();

        $this->assertNull($handler->parseCallbackData('close_topic'));
        $this->assertNull($handler->parseCallbackData('feedback_rate_42'));
        $this->assertNull($handler->parseCallbackData('feedback_rate_42_100_6'));
        $this->assertNull($handler->parseCallbackData('feedback_rate_42_100_0'));
    }

    public function test_all_valid_scores_1_to_5_are_accepted(): void
    {
        $botUser = BotUser::create(['chat_id' => 1005, 'platform' => 'telegram']);

        for ($score = 1; $score <= 5; $score++) {
            $feedback = Feedback::create([
                'bot_user_id' => $botUser->id,
                'status' => 'awaiting_rating',
                'closed_at' => now(),
            ]);

            (new HandleFeedbackRating())->execute("feedback_rate_{$botUser->id}_{$feedback->id}_{$score}");

            $feedback->refresh();
            $this->assertEquals($score, $feedback->rating);
            $this->assertEquals('completed_no_comment', $feedback->status);
        }
    }
}
