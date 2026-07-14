<?php

namespace Tests\Unit\Modules\Admin\Actions;

use App\Models\BotUser;
use App\Modules\Admin\Actions\BanBotUser;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BanBotUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_marks_user_banned_and_closed(): void
    {
        $botUser = BotUser::create(['chat_id' => 3001, 'platform' => 'telegram']);

        (new BanBotUser())->execute($botUser);

        $botUser->refresh();
        $this->assertTrue($botUser->isBanned());
        $this->assertNotNull($botUser->banned_at);
        $this->assertTrue($botUser->isClosed());
        $this->assertNotNull($botUser->closed_at);
        Queue::assertPushed(SendTelegramSimpleQueryJob::class, fn (SendTelegramSimpleQueryJob $job): bool =>
            $job->queryParams->methodQuery === 'sendMessage'
            && (string) $job->queryParams->chat_id === (string) $botUser->chat_id);
    }

    public function test_closes_forum_topic_when_topic_id_present(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-1009876543210');

        $botUser = BotUser::create(['chat_id' => 3002, 'platform' => 'telegram', 'topic_id' => 777]);

        (new BanBotUser())->execute($botUser);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendTelegramSimpleQueryJob::class] ?? [];
        $methods = array_map(fn ($p) => $p['job']->queryParams->methodQuery, $pushed);

        $this->assertContains('editForumTopic', $methods);
        $this->assertContains('closeForumTopic', $methods);
    }

    public function test_does_not_close_topic_without_topic_id(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-1009876543210');

        $botUser = BotUser::create(['chat_id' => 3003, 'platform' => 'telegram']);

        (new BanBotUser())->execute($botUser);

        Queue::assertPushed(SendTelegramSimpleQueryJob::class, 1);
    }

    public function test_noop_when_already_banned(): void
    {
        app(SettingsService::class)->set('telegram.group_id', '-1009876543210');

        $botUser = BotUser::create([
            'chat_id' => 3004,
            'platform' => 'telegram',
            'topic_id' => 888,
            'is_banned' => true,
            'banned_at' => now()->subDay(),
        ]);

        (new BanBotUser())->execute($botUser);

        Queue::assertNothingPushed();
    }
}
