<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddUnavailableStateToBotUsersTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_default_rollback_and_repeated_up(): void
    {
        $migration = require database_path('migrations/2026_08_29_120000_add_unavailable_state_to_bot_users_table.php');

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('bot_users', 'is_unavailable'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'unavailable_reason'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'unavailable_at'));

        $botUser = BotUser::create([
            'chat_id' => 9401,
            'platform' => 'telegram',
        ]);
        $botUser->refresh();

        $this->assertFalse($botUser->is_unavailable);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('bot_users', 'is_unavailable'));
        $this->assertFalse(Schema::hasColumn('bot_users', 'unavailable_reason'));
        $this->assertFalse(Schema::hasColumn('bot_users', 'unavailable_at'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('bot_users', 'is_unavailable'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'unavailable_reason'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'unavailable_at'));
    }
}
