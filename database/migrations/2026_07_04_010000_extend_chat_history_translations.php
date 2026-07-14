<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('bot_users', function (Blueprint $table): void {
            $table->string('chat_translation_locale', 16)->nullable()->after('preferred_language_selected_at')->index();
            $table->timestamp('chat_translation_locale_selected_at')->nullable()->after('chat_translation_locale');
        });

        Schema::table('message_translations', function (Blueprint $table): void {
            $table->string('error_message', 1024)->nullable()->after('source_hash');
            $table->timestamp('manual_edited_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('message_translations', function (Blueprint $table): void {
            $table->dropColumn(['error_message', 'manual_edited_at']);
        });

        Schema::table('bot_users', function (Blueprint $table): void {
            $table->dropIndex(['chat_translation_locale']);
            $table->dropColumn(['chat_translation_locale', 'chat_translation_locale_selected_at']);
        });
    }
};
