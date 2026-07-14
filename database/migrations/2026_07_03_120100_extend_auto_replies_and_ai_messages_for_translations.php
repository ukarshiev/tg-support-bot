<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Добавить поля для типов автоответов и двухъязычных ИИ-черновиков.
     */
    public function up(): void
    {
        Schema::table('auto_replies', function (Blueprint $table): void {
            $table->string('type', 64)->default('regular')->after('id')->index();
            $table->string('source_locale', 16)->default('ru')->after('type');
            $table->string('source_hash', 64)->nullable()->after('response')->index();
        });

        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->text('text_source')->nullable()->after('text_ai');
            $table->text('text_translated')->nullable()->after('text_source');
            $table->string('source_locale', 16)->default('ru')->after('text_translated');
            $table->string('target_locale', 16)->nullable()->after('source_locale');
            $table->string('translation_provider', 64)->nullable()->after('target_locale');
            $table->string('translation_status', 32)->default('empty')->after('translation_provider')->index();
            $table->string('translation_source', 32)->default('auto')->after('translation_status');
            $table->string('source_hash', 64)->nullable()->after('translation_source')->index();
            $table->timestamp('translated_at')->nullable()->after('source_hash');
        });
    }

    /**
     * Откатить поля переводов.
     */
    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'text_source',
                'text_translated',
                'source_locale',
                'target_locale',
                'translation_provider',
                'translation_status',
                'translation_source',
                'source_hash',
                'translated_at',
            ]);
        });

        Schema::table('auto_replies', function (Blueprint $table): void {
            $table->dropColumn(['type', 'source_locale', 'source_hash']);
        });
    }
};
