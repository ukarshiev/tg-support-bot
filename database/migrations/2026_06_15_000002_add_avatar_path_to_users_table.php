<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Add avatar_path to users table.
     *
     * Stores the relative path on the `local` disk (e.g. `avatars/user-42.jpg`).
     * Nullable — users without an uploaded photo fall back to deterministic initials.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('role');
        });
    }

    /**
     * Remove avatar_path from users table.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
