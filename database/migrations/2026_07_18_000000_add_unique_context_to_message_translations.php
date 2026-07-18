<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                DELETE FROM message_translations a
                USING message_translations b
                WHERE a.id < b.id
                  AND a.message_id = b.message_id
                  AND COALESCE(a.direction, '') = COALESCE(b.direction, '')
                  AND COALESCE(a.source_locale, '') = COALESCE(b.source_locale, '')
                  AND COALESCE(a.target_locale, '') = COALESCE(b.target_locale, '')
                  AND COALESCE(a.source_hash, '') = COALESCE(b.source_hash, '')
            SQL);
        } else {
            DB::statement(<<<'SQL'
                DELETE FROM message_translations
                WHERE id NOT IN (
                    SELECT keep_id FROM (
                        SELECT MAX(id) AS keep_id
                        FROM message_translations
                        GROUP BY message_id, direction, source_locale, target_locale, source_hash
                    ) AS deduplicated
                )
            SQL);
        }

        Schema::table('message_translations', function (Blueprint $table): void {
            $table->unique(
                ['message_id', 'direction', 'source_locale', 'target_locale', 'source_hash'],
                'message_translations_unique_context'
            );
        });
    }

    public function down(): void
    {
        Schema::table('message_translations', function (Blueprint $table): void {
            $table->dropUnique('message_translations_unique_context');
        });
    }
};
