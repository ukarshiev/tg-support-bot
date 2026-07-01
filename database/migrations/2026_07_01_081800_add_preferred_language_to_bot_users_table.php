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
        Schema::table('bot_users', function (Blueprint $table) {
            $table->string('preferred_language_code', 16)->nullable()->after('platform')->index();
            $table->string('preferred_language_name')->nullable()->after('preferred_language_code');
            $table->timestamp('preferred_language_selected_at')->nullable()->after('preferred_language_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_users', function (Blueprint $table) {
            $table->dropIndex(['preferred_language_code']);
            $table->dropColumn([
                'preferred_language_code',
                'preferred_language_name',
                'preferred_language_selected_at',
            ]);
        });
    }
};
