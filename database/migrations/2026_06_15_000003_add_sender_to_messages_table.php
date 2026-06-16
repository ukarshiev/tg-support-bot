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
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('sender_user_id')
                ->nullable()
                ->after('to_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('sender_name')->nullable()->after('sender_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['sender_user_id']);
            $table->dropColumn(['sender_user_id', 'sender_name']);
        });
    }
};
