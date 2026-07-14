<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('bot_users', 'identity_key')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->char('identity_key', 64)->nullable()->after('platform');
            });
        }

        $this->nullDuplicateKeys('bot_users', 'identity_key');

        if (!Schema::hasIndex('bot_users', 'bot_users_identity_key_unique')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->unique('identity_key', 'bot_users_identity_key_unique');
            });
        }

        $occupiedKeys = DB::table('bot_users')
            ->whereNotNull('identity_key')
            ->pluck('identity_key')
            ->all();
        $occupiedKeys = array_fill_keys($occupiedKeys, true);

        DB::table('bot_users')
            ->select(['id', 'platform', 'chat_id', 'identity_key'])
            ->orderBy('id')
            ->chunkById(500, function ($users) use (&$occupiedKeys): void {
                foreach ($users as $user) {
                    $key = $this->identityKey((string) $user->platform, (string) $user->chat_id);

                    if (isset($occupiedKeys[$key])) {
                        continue;
                    }

                    DB::table('bot_users')
                        ->where('id', $user->id)
                        ->whereNull('identity_key')
                        ->update(['identity_key' => $key]);
                    $occupiedKeys[$key] = true;
                }
            });

        if (!Schema::hasColumn('messages', 'source_event_key')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->char('source_event_key', 64)->nullable()->after('platform');
            });
        }

        $this->nullDuplicateKeys('messages', 'source_event_key');

        if (!Schema::hasIndex('messages', 'messages_source_event_key_unique')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->unique('source_event_key', 'messages_source_event_key_unique');
            });
        }

        if (!Schema::hasColumn('messages', 'message_kind')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->string('message_kind', 32)->default('chat')->after('message_type')->index();
            });
        }

        if (!Schema::hasColumn('messages', 'delivery_status')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->string('delivery_status', 32)->nullable()->after('message_kind')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'delivery_status')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropIndex(['delivery_status']);
                $table->dropColumn('delivery_status');
            });
        }

        if (Schema::hasColumn('messages', 'message_kind')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropIndex(['message_kind']);
                $table->dropColumn('message_kind');
            });
        }

        if (Schema::hasColumn('messages', 'source_event_key')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->dropUnique('messages_source_event_key_unique');
                $table->dropColumn('source_event_key');
            });
        }

        if (Schema::hasColumn('bot_users', 'identity_key')) {
            Schema::table('bot_users', function (Blueprint $table): void {
                $table->dropUnique('bot_users_identity_key_unique');
                $table->dropColumn('identity_key');
            });
        }
    }

    private function identityKey(string $platform, string|int $chatId): string
    {
        return hash('sha256', strtolower(trim($platform)) . "\0" . (string) $chatId);
    }

    /**
     * Сохраняет самую раннюю запись с новым служебным ключом, а у повторов
     * очищает только этот ключ. Сами legacy-строки и их пользовательские данные
     * остаются без изменений.
     */
    private function nullDuplicateKeys(string $table, string $column): void
    {
        $duplicateKeys = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($duplicateKeys as $key) {
            $canonicalId = DB::table($table)
                ->where($column, $key)
                ->min('id');

            DB::table($table)
                ->where($column, $key)
                ->where('id', '<>', $canonicalId)
                ->update([$column => null]);
        }
    }
};
