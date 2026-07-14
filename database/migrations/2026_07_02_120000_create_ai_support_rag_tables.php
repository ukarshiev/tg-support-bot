<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ai_support_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_path');
            $table->string('mode')->default('activate');
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('chunks_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['mode', 'created_at']);
        });

        Schema::create('ai_support_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('source_file');
            $table->string('telegram_message_id');
            $table->timestamp('message_datetime')->nullable();
            $table->string('sender_name');
            $table->string('sender_role', 32);
            $table->text('text');
            $table->boolean('is_noise')->default(false);
            $table->timestamps();

            $table->unique(['source_file', 'telegram_message_id'], 'ai_support_messages_source_unique');
            $table->index(['sender_role', 'is_noise']);
            $table->index('message_datetime');
        });

        Schema::create('ai_support_knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->string('source_hash')->unique();
            $table->text('question');
            $table->text('answer');
            $table->json('keywords')->nullable();
            $table->json('embedding')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index('first_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_support_knowledge_chunks');
        Schema::dropIfExists('ai_support_messages');
        Schema::dropIfExists('ai_support_import_batches');
    }
};
