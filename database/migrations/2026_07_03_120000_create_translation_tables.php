<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Создать таблицы переводческого слоя.
     */
    public function up(): void
    {
        Schema::create('translation_cache_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_locale', 16)->index();
            $table->string('target_locale', 16)->index();
            $table->string('source_hash', 64)->index();
            $table->text('source_text');
            $table->longText('translated_text')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('status', 32)->default('ready')->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['source_locale', 'target_locale', 'source_hash'], 'translation_cache_locale_hash_unique');
        });

        Schema::create('translation_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64)->index();
            $table->string('source_locale', 16)->nullable()->index();
            $table->string('target_locale', 16)->nullable()->index();
            $table->unsignedInteger('characters')->default(0);
            $table->boolean('success')->default(false)->index();
            $table->string('error_code', 64)->nullable()->index();
            $table->string('error_message', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('auto_reply_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('auto_reply_id')->constrained('auto_replies')->onDelete('cascade');
            $table->string('locale', 16)->index();
            $table->longText('text')->nullable();
            $table->string('status', 32)->default('empty')->index();
            $table->string('source', 32)->default('auto')->index();
            $table->string('provider', 64)->nullable();
            $table->string('source_hash', 64)->nullable()->index();
            $table->timestamp('translated_at')->nullable();
            $table->timestamps();

            $table->unique(['auto_reply_id', 'locale'], 'auto_reply_translations_reply_locale_unique');
        });

        Schema::create('message_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            $table->string('source_locale', 16)->nullable()->index();
            $table->string('target_locale', 16)->index();
            $table->longText('source_text')->nullable();
            $table->longText('translated_text')->nullable();
            $table->string('direction', 32)->default('operator_to_client')->index();
            $table->string('status', 32)->default('ready')->index();
            $table->string('source', 32)->default('auto')->index();
            $table->string('provider', 64)->nullable();
            $table->string('source_hash', 64)->nullable()->index();
            $table->timestamp('translated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Удалить таблицы переводческого слоя.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_translations');
        Schema::dropIfExists('auto_reply_translations');
        Schema::dropIfExists('translation_usage_logs');
        Schema::dropIfExists('translation_cache_entries');
    }
};
