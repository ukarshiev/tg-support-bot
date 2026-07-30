<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnifyClientLanguageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_newest_language_is_preserved_in_the_single_runtime_source(): void
    {
        $migration = require database_path('migrations/2026_07_29_000000_backfill_single_client_language.php');

        $chatIsNewer = BotUser::create([
            'chat_id' => 9101,
            'platform' => 'telegram',
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
            'preferred_language_selected_at' => '2026-07-01 10:00:00',
        ]);
        $clientIsNewer = BotUser::create([
            'chat_id' => 9102,
            'platform' => 'telegram',
            'preferred_language_code' => 'es',
            'preferred_language_name' => 'Español',
            'preferred_language_selected_at' => '2026-07-03 10:00:00',
        ]);

        DB::table('bot_users')->where('id', $chatIsNewer->id)->update([
            'chat_translation_locale' => 'tr',
            'chat_translation_locale_selected_at' => '2026-07-02 10:00:00',
        ]);
        DB::table('bot_users')->where('id', $clientIsNewer->id)->update([
            'chat_translation_locale' => 'tr',
            'chat_translation_locale_selected_at' => '2026-07-02 10:00:00',
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('bot_users', 'chat_translation_locale'));
        $this->assertTrue(Schema::hasColumn('bot_users', 'chat_translation_locale_selected_at'));
        $this->assertDatabaseHas('bot_users', [
            'id' => $chatIsNewer->id,
            'preferred_language_code' => 'tr',
            'preferred_language_name' => null,
        ]);
        $this->assertDatabaseHas('bot_users', [
            'id' => $clientIsNewer->id,
            'preferred_language_code' => 'es',
            'preferred_language_name' => 'Español',
        ]);
    }
}
