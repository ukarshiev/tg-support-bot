<?php

namespace Tests\Feature\Database;

use App\Models\BotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConversationIntegrityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_legacy_duplicates_is_repeatable_and_rolls_back_without_data_loss(): void
    {
        $migration = require database_path('migrations/2026_07_13_235000_add_conversation_integrity_keys.php');
        $migration->down();

        $firstDuplicateId = DB::table('bot_users')->insertGetId([
            'chat_id' => 900,
            'platform' => 'telegram',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondDuplicateId = DB::table('bot_users')->insertGetId([
            'chat_id' => 900,
            'platform' => 'telegram',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $uniqueId = DB::table('bot_users')->insertGetId([
            'chat_id' => 900,
            'platform' => 'vk',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('messages')->insert([
            [
                'bot_user_id' => $firstDuplicateId,
                'platform' => 'telegram',
                'message_type' => 'incoming',
                'from_id' => 42,
                'to_id' => 0,
                'text' => 'Старый дубль 1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bot_user_id' => $secondDuplicateId,
                'platform' => 'telegram',
                'message_type' => 'incoming',
                'from_id' => 42,
                'to_id' => 0,
                'text' => 'Старый дубль 2',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        try {
            $migration->up();
            $migration->up();

            $this->assertDatabaseCount('bot_users', 3);
            $this->assertDatabaseCount('messages', 2);
            $this->assertTrue(Schema::hasIndex('bot_users', 'bot_users_identity_key_unique'));
            $this->assertTrue(Schema::hasIndex('messages', 'messages_source_event_key_unique'));
            $this->assertNotNull(DB::table('bot_users')->where('id', $firstDuplicateId)->value('identity_key'));
            $this->assertNull(DB::table('bot_users')->where('id', $secondDuplicateId)->value('identity_key'));
            $this->assertNotNull(DB::table('bot_users')->where('id', $uniqueId)->value('identity_key'));
            $this->assertSame($firstDuplicateId, BotUser::findByPlatformChat(900, 'telegram')?->id);
            $this->assertNull(DB::table('messages')->where('text', 'Старый дубль 1')->value('source_event_key'));
            $this->assertSame('chat', DB::table('messages')->where('text', 'Старый дубль 1')->value('message_kind'));

            $migration->down();
            $migration->down();

            $this->assertFalse(Schema::hasColumn('bot_users', 'identity_key'));
            $this->assertFalse(Schema::hasColumn('messages', 'source_event_key'));
            $this->assertFalse(Schema::hasColumn('messages', 'message_kind'));
            $this->assertFalse(Schema::hasColumn('messages', 'delivery_status'));
            $this->assertDatabaseCount('bot_users', 3);
            $this->assertDatabaseCount('messages', 2);
        } finally {
            $migration->up();
        }
    }
}
