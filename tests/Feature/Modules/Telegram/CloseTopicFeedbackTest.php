<?php

namespace Tests\Feature\Modules\Telegram;

use App\Models\BotUser;
use App\Models\Feedback;
use App\Modules\Telegram\Actions\CloseTopic;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloseTopicFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(\App\Services\Settings\SettingsService::class)->set('telegram.group_id', '-100123456789');
        config(['icons.outgoing' => '']);
        Queue::fake();
    }

    public function test_close_topic_creates_feedback_record(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 50001,
            'platform' => 'telegram',
            'topic_id' => 111,
            'is_closed' => false,
        ]);

        app(CloseTopic::class)->execute($botUser);

        $this->assertDatabaseHas('feedbacks', [
            'bot_user_id' => $botUser->id,
            'status' => 'awaiting_rating',
            'rating' => null,
        ]);
    }

    public function test_close_topic_dispatches_feedback_form_job(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 50002,
            'platform' => 'telegram',
            'topic_id' => 112,
            'is_closed' => false,
        ]);

        app(CloseTopic::class)->execute($botUser);

        // SendFeedbackForm dispatches SendTelegramSimpleQueryJob for the rating form
        // CloseTopic itself dispatches editForumTopic + closeForumTopic + close message + feedback form
        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];

        // At minimum: editForumTopic, closeForumTopic, close message to user, feedback form
        $this->assertGreaterThanOrEqual(3, count($pushed));

        // The last job dispatched should be the feedback form (sendMessage to user's chat_id)
        /** @phpstan-ignore-next-line */
        $feedbackJob = collect($pushed)->firstWhere(
            fn ($item) => $item['job']->queryParams->chat_id === $botUser->chat_id
            && $item['job']->queryParams->methodQuery === 'sendMessage'
            && isset($item['job']->queryParams->reply_markup['inline_keyboard'])
        );

        $this->assertNotNull($feedbackJob, 'Feedback form job was not dispatched');
    }

    public function test_close_topic_does_not_create_feedback_when_already_closed(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 50003,
            'platform' => 'telegram',
            'topic_id' => 113,
            'is_closed' => true,
        ]);

        app(CloseTopic::class)->execute($botUser);

        $this->assertDatabaseCount('feedbacks', 0);
    }

    public function test_multiple_close_events_create_multiple_feedback_records(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 50004,
            'platform' => 'telegram',
            'topic_id' => 114,
            'is_closed' => false,
        ]);

        app(CloseTopic::class)->execute($botUser);

        // Re-open for second close
        $botUser->update(['is_closed' => false]);

        app(CloseTopic::class)->execute($botUser);

        $this->assertEquals(2, Feedback::where('bot_user_id', $botUser->id)->count());
    }

    public function test_close_topic_sets_bot_user_is_closed_true(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 50005,
            'platform' => 'telegram',
            'topic_id' => 115,
            'is_closed' => false,
        ]);

        app(CloseTopic::class)->execute($botUser);

        $this->assertTrue($botUser->fresh()->isClosed());
    }
}
