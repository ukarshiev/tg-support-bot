<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasIndex('bot_users', 'bot_users_chat_translation_locale_index')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->dropIndex('bot_users_chat_translation_locale_index');
            });
        }

        $columns = array_values(array_filter(
            ['chat_translation_locale', 'chat_translation_locale_selected_at'],
            static fn (string $column): bool => Schema::hasColumn('bot_users', $column),
        ));

        if ($columns !== []) {
            Schema::table('bot_users', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('bot_users', 'chat_translation_locale')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->string('chat_translation_locale', 16)
                    ->nullable()
                    ->after('preferred_language_selected_at');
            });
        }

        if (!Schema::hasColumn('bot_users', 'chat_translation_locale_selected_at')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->timestamp('chat_translation_locale_selected_at')
                    ->nullable()
                    ->after('chat_translation_locale');
            });
        }

        if (!Schema::hasIndex('bot_users', 'bot_users_chat_translation_locale_index')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->index('chat_translation_locale');
            });
        }

        DB::table('bot_users')
            ->whereNotNull('preferred_language_code')
            ->update([
                'chat_translation_locale' => DB::raw('preferred_language_code'),
                'chat_translation_locale_selected_at' => DB::raw('preferred_language_selected_at'),
            ]);
    }
};
