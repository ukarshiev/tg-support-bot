<?php

namespace Tests\Unit\Modules\Admin\Actions;

use App\Models\BotUser;
use App\Modules\Admin\Actions\UnbanBotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnbanBotUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_ban_fields(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 4001,
            'platform' => 'telegram',
            'is_banned' => true,
            'banned_at' => now()->subDay(),
        ]);

        (new UnbanBotUser())->execute($botUser);

        $botUser->refresh();
        $this->assertFalse($botUser->isBanned());
        $this->assertNull($botUser->banned_at);
    }

    public function test_does_not_reopen_closed_conversation(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 4002,
            'platform' => 'telegram',
            'is_banned' => true,
            'banned_at' => now()->subDay(),
            'is_closed' => true,
            'closed_at' => now()->subDay(),
        ]);

        (new UnbanBotUser())->execute($botUser);

        $botUser->refresh();
        $this->assertFalse($botUser->isBanned());
        $this->assertTrue($botUser->isClosed());
    }

    public function test_noop_when_not_banned(): void
    {
        $botUser = BotUser::create(['chat_id' => 4003, 'platform' => 'telegram', 'is_banned' => false]);

        (new UnbanBotUser())->execute($botUser);

        $botUser->refresh();
        $this->assertFalse($botUser->isBanned());
    }
}
