<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropLegacyClientLanguageColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_columns_are_removed_and_rollback_restores_canonical_language(): void
    {
        $migration = require database_path('migrations/2026_07_30_000000_drop_legacy_client_language_columns.php');

        $botUser = BotUser::create([
            'chat_id' => 9301,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => '2026-07-30 10:00:00',
        ]);

        $this->assertFalse(Schema::hasColumn('bot_users', 'chat_translation_locale'));
        $this->assertFalse(Schema::hasColumn('bot_users', 'chat_translation_locale_selected_at'));

        $migration->down();

        $this->assertTrue(Schema::hasColumn('bot_users', 'chat_translation_locale'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'chat_translation_locale_selected_at'));
        $this->assertDatabaseHas('bot_users', [
            'id' => $botUser->id,
            'preferred_language_code' => 'tr',
            'chat_translation_locale' => 'tr',
        ]);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('bot_users', 'chat_translation_locale'));
        $this->assertFalse(Schema::hasColumn('bot_users', 'chat_translation_locale_selected_at'));
        $this->assertDatabaseHas('bot_users', [
            'id' => $botUser->id,
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
        ]);
    }
}
