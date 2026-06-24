<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bot_users', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('platform');
            $table->string('username')->nullable()->after('display_name');
            $table->string('avatar_path')->nullable()->after('username');
            $table->timestamp('profile_synced_at')->nullable()->after('avatar_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'username', 'avatar_path', 'profile_synced_at']);
        });
    }
};
