<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('ai_messages')
            ->select('bot_user_id', 'source_hash', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('source_hash')
            ->groupBy('bot_user_id', 'source_hash')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('bot_user_id')
            ->get()
            ->each(function (object $duplicate): void {
                DB::table('ai_messages')
                    ->where('bot_user_id', $duplicate->bot_user_id)
                    ->where('source_hash', $duplicate->source_hash)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->update(['source_hash' => null]);
            });

        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->unique(['bot_user_id', 'source_hash'], 'ai_messages_user_source_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropUnique('ai_messages_user_source_hash_unique');
        });
    }
};
