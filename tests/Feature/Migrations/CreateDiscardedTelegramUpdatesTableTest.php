<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateDiscardedTelegramUpdatesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_supports_up_down_and_repeated_up(): void
    {
        $migration = require database_path('migrations/2026_08_29_130000_create_discarded_telegram_updates_table.php');

        $this->assertTrue(Schema::hasTable('discarded_telegram_updates'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('discarded_telegram_updates'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('discarded_telegram_updates', [
            'update_id',
            'payload',
            'http_status',
            'attempts',
            'discarded_at',
        ]));

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasTable('discarded_telegram_updates'));
    }
}
