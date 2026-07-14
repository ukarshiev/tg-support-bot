<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Models\BotUser;
use App\Modules\Feedback\Jobs\DeliverFeedbackFormJob;
use App\Modules\Max\Jobs\SendMaxSimpleMessageJob;
use App\Modules\Telegram\Actions\CloseTopic;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Modules\Vk\Jobs\SendVkSimpleMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CloseTopicTest extends TestCase
{
    use RefreshDatabase;

    private int $groupId;

    public function setUp(): void
    {
        parent::setUp();

        $this->groupId = time();
        app(\App\Services\Settings\SettingsService::class)->set('telegram.group_id', (string) $this->groupId);

        Queue::fake();
    }

    public function test_close_topic_other_platform(): void
    {
        $chatId = time();
        $botUser = BotUser::getUserByChatId($chatId, 'test');
        $botUser->update(['topic_id' => 100]);

        app(CloseTopic::class)->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(2, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('editForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);

        $job = $pushed[1]['job'];
        $this->assertEquals('closeForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);
    }

    public function test_close_topic_telegram(): void
    {
        $chatId = time();
        $botUser = BotUser::getUserByChatId($chatId, 'telegram');
        $botUser->update(['topic_id' => 101]);

        app(CloseTopic::class)->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        // 3 Telegram jobs: close message, icon, close topic. Feedback has its own reliable job.
        $this->assertCount(3, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery);
        $this->assertEquals($botUser->chat_id, $job->queryParams->chat_id);

        $job = $pushed[1]['job'];
        $this->assertEquals('editForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);

        $job = $pushed[2]['job'];
        $this->assertEquals('closeForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);

        Queue::assertPushed(DeliverFeedbackFormJob::class);
    }

    public function test_close_topic_vk(): void
    {
        $chatId = time();
        $botUser = BotUser::getUserByChatId($chatId, 'vk');
        $botUser->update(['topic_id' => 102]);

        app(CloseTopic::class)->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendVkSimpleMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('messages.send', $job->queryParams->methodQuery);
        $this->assertEquals($botUser->chat_id, $job->queryParams->peer_id);

        Queue::assertPushed(DeliverFeedbackFormJob::class);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $this->assertCount(2, $pushed);

        $job = $pushed[0]['job'];
        $this->assertEquals('editForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);

        $job = $pushed[1]['job'];
        $this->assertEquals('closeForumTopic', $job->queryParams->methodQuery);
        $this->assertEquals($this->groupId, $job->queryParams->chat_id);
        $this->assertEquals($botUser->topic_id, $job->queryParams->message_thread_id);
    }

    public function test_close_topic_max_sends_close_message_and_feedback_form(): void
    {
        $botUser = BotUser::getUserByChatId(time(), 'max');

        app(CloseTopic::class)->execute($botUser);

        Queue::assertPushed(SendMaxSimpleMessageJob::class, function (SendMaxSimpleMessageJob $job) use ($botUser): bool {
            return (string) $job->queryParams->user_id === (string) $botUser->chat_id
                && $job->queryParams->text === 'Your request has been closed!';
        });

        Queue::assertPushed(DeliverFeedbackFormJob::class);
    }
}
