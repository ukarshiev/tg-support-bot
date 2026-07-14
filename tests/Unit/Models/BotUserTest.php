<?php

namespace Tests\Unit\Models;

use App\Jobs\EnrichBotUserProfileJob;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BotUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_relation_uses_bot_user_foreign_key(): void
    {
        $owner = BotUser::create(['chat_id' => 101, 'platform' => 'telegram']);
        $other = BotUser::create(['chat_id' => 102, 'platform' => 'telegram']);

        $ownedMessage = Message::create([
            'bot_user_id' => $owner->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 501,
            'to_id' => 0,
        ]);
        Message::create([
            'bot_user_id' => $other->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 502,
            'to_id' => 0,
        ]);

        $this->assertCount(1, $owner->messages);
        $this->assertTrue($owner->messages->first()->is($ownedMessage));
    }

    public function test_same_chat_id_on_different_platforms_creates_different_users(): void
    {
        Queue::fake();

        $telegram = BotUser::getUserByChatId(777, 'telegram');
        $vk = BotUser::getUserByChatId(777, 'vk');

        $this->assertNotNull($telegram);
        $this->assertNotNull($vk);
        $this->assertFalse($telegram->is($vk));
        $this->assertSame(BotUser::identityKey('telegram', 777), $telegram->identity_key);
        $this->assertSame(BotUser::identityKey('vk', 777), $vk->identity_key);
    }

    // ── Helper: build a minimal TelegramUpdateDto ──────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeDto(array $overrides = []): TelegramUpdateDto
    {
        return new TelegramUpdateDto(
            updateId: 1,
            typeQuery: 'message',
            aiTechMessage: false,
            typeSource: $overrides['typeSource'] ?? 'private',
            isBot: false,
            chatId: $overrides['chatId'] ?? 100,
            username: $overrides['username'] ?? null,
            displayName: $overrides['displayName'] ?? null,
        );
    }

    // ── Profile sync on creation ───────────────────────────────────────────────

    public function test_sync_display_name_on_new_user_creation(): void
    {
        Queue::fake();

        $dto = $this->makeDto(['chatId' => 200, 'displayName' => 'Ivan Petrov', 'username' => 'ivan_p']);

        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);

        $this->assertNotNull($botUser);
        $this->assertSame('Ivan Petrov', $botUser->display_name);
        $this->assertSame('ivan_p', $botUser->username);
        $this->assertTrue($botUser->wasRecentlyCreated);

        Queue::assertPushed(EnrichBotUserProfileJob::class);
    }

    // ── Opportunistic update when name changes ─────────────────────────────────

    public function test_opportunistic_update_when_name_changes(): void
    {
        Queue::fake();

        // Pre-existing user with old name.
        BotUser::create([
            'chat_id' => 300,
            'platform' => 'telegram',
            'display_name' => 'Old Name',
            'username' => 'old_handle',
        ]);

        $dto = $this->makeDto(['chatId' => 300, 'displayName' => 'New Name', 'username' => 'new_handle']);

        $botUser = BotUser::getOrCreateByTelegramUpdate($dto);

        $this->assertNotNull($botUser);
        $botUser->refresh();
        $this->assertSame('New Name', $botUser->display_name);
        $this->assertSame('new_handle', $botUser->username);
    }

    // ── No write when name is unchanged ───────────────────────────────────────

    public function test_no_write_when_name_unchanged(): void
    {
        Queue::fake();

        BotUser::create([
            'chat_id' => 400,
            'platform' => 'telegram',
            'display_name' => 'Same Name',
            'username' => 'same_handle',
        ]);

        $dto = $this->makeDto(['chatId' => 400, 'displayName' => 'Same Name', 'username' => 'same_handle']);

        DB::enableQueryLog();
        BotUser::getOrCreateByTelegramUpdate($dto);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $updateQueries = array_filter($log, static fn ($q) => str_contains(strtolower($q['query']), 'update'));

        // Should only be the firstOrCreate SELECT + no UPDATE for profile fields.
        $profileUpdates = array_filter($updateQueries, static fn ($q) => str_contains($q['query'], 'display_name'));
        $this->assertCount(0, $profileUpdates);
    }
}
