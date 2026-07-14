<?php

namespace Tests\Unit\Modules\Feedback\Actions;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Models\Message;
use App\Modules\Feedback\Actions\HandleFeedbackRating;
use App\Modules\Feedback\Jobs\DeliverFeedbackThankYouJob;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
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

        Queue::assertPushed(DeliverFeedbackThankYouJob::class, function ($job) use ($feedback, $botUser): bool {
            return $job->feedbackId === $feedback->id
                && $job->telegramMessageId === 9988
                && $job->telegramChatId === (int) $botUser->chat_id;
        });
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

        Queue::assertPushed(DeliverFeedbackThankYouJob::class, fn ($job): bool => $job->feedbackId === $feedback->id);
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

    public function test_rejects_callback_when_feedback_belongs_to_another_user(): void
    {
        $owner = BotUser::create(['chat_id' => 10041, 'platform' => 'telegram']);
        $attacker = BotUser::create(['chat_id' => 10042, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $owner->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute("feedback_rate_{$attacker->id}_{$feedback->id}_5");

        $feedback->refresh();
        $this->assertNull($feedback->rating);
        $this->assertSame('awaiting_rating', $feedback->status);
        Queue::assertNothingPushed();
    }

    public function test_vk_rating_dispatches_localized_thank_you_message(): void
    {
        $botUser = BotUser::create(['chat_id' => 10043, 'platform' => 'vk', 'preferred_language_code' => 'en']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute("feedback_rate_{$botUser->id}_{$feedback->id}_5");

        Queue::assertPushed(DeliverFeedbackThankYouJob::class, function ($job) use ($feedback): bool {
            return $job->feedbackId === $feedback->id
                && $job->text === 'Thank you for your feedback! Your rating has been recorded.';
        });
    }

    public function test_duplicate_rating_callback_is_ignored_atomically(): void
    {
        $botUser = BotUser::create(['chat_id' => 10044, 'platform' => 'vk', 'preferred_language_code' => 'en']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);
        $handler = new HandleFeedbackRating();

        $handler->execute("feedback_rate_{$botUser->id}_{$feedback->id}_4", actor: $botUser);
        $handler->execute("feedback_rate_{$botUser->id}_{$feedback->id}_1", actor: $botUser);

        $this->assertSame(4, $feedback->fresh()->rating);
        $this->assertSame(1, Message::query()->where('bot_user_id', $botUser->id)->count());
        Queue::assertPushed(DeliverFeedbackThankYouJob::class, 1);
    }

    public function test_callback_from_another_actual_actor_is_rejected(): void
    {
        $owner = BotUser::create(['chat_id' => 10045, 'platform' => 'vk']);
        $actor = BotUser::create(['chat_id' => 10046, 'platform' => 'vk']);
        $feedback = Feedback::create([
            'bot_user_id' => $owner->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute(
            "feedback_rate_{$owner->id}_{$feedback->id}_5",
            actor: $actor,
        );

        $this->assertNull($feedback->fresh()->rating);
        Queue::assertNothingPushed();
    }

    public function test_records_rating_as_incoming_chat_message(): void
    {
        $botUser = BotUser::create(['chat_id' => 2001, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute("feedback_rate_{$botUser->id}_{$feedback->id}_5");

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'incoming',
            'text' => 'Оценка обращения: ' . str_repeat('⭐', 5) . ' (5/5)',
        ]);
    }

    public function test_posts_rating_to_group_topic_when_topic_id_present(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-1001234567890');

        $botUser = BotUser::create(['chat_id' => 2002, 'platform' => 'telegram', 'topic_id' => 555]);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute("feedback_rate_{$botUser->id}_{$feedback->id}_4");

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $topicJobs = array_values(array_filter(
            $pushed,
            fn ($p) => $p['job']->queryParams->methodQuery === 'sendMessage'
        ));

        $this->assertNotEmpty($topicJobs);
        $job = $topicJobs[0]['job'];
        $this->assertEquals('-1001234567890', $job->queryParams->chat_id);
        $this->assertEquals(555, $job->queryParams->message_thread_id);
    }

    public function test_does_not_post_to_group_topic_without_topic_id(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-1001234567890');

        $botUser = BotUser::create(['chat_id' => 2003, 'platform' => 'telegram']);
        $feedback = Feedback::create([
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'closed_at' => now(),
        ]);

        (new HandleFeedbackRating())->execute("feedback_rate_{$botUser->id}_{$feedback->id}_4");

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $sendMessageJobs = array_filter(
            $pushed,
            fn ($p) => $p['job']->queryParams->methodQuery === 'sendMessage'
        );

        $this->assertEmpty($sendMessageJobs);
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
