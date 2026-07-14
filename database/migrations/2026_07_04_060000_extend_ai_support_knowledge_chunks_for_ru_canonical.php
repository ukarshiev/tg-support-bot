<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_support_knowledge_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_original')) {
                $table->text('question_original')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_ru')) {
                $table->text('question_ru')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_original')) {
                $table->text('answer_original')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_ru')) {
                $table->text('answer_ru')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'ai_instruction')) {
                $table->text('ai_instruction')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'source_locale')) {
                $table->string('source_locale', 16)->default('auto')->index();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'target_locale')) {
                $table->string('target_locale', 16)->default('ru')->index();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_translation_status')) {
                $table->string('question_translation_status', 32)->default('pending')->index();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_translation_status')) {
                $table->string('answer_translation_status', 32)->default('pending')->index();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_translation_provider')) {
                $table->string('question_translation_provider', 64)->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_translation_provider')) {
                $table->string('answer_translation_provider', 64)->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_translation_error')) {
                $table->text('question_translation_error')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_translation_error')) {
                $table->text('answer_translation_error')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_translated_at')) {
                $table->timestamp('question_translated_at')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_translated_at')) {
                $table->timestamp('answer_translated_at')->nullable();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'question_ru_manually_edited')) {
                $table->boolean('question_ru_manually_edited')->default(false)->index();
            }
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'answer_ru_manually_edited')) {
                $table->boolean('answer_ru_manually_edited')->default(false)->index();
            }
        });

        DB::table('ai_support_knowledge_chunks')
            ->whereNull('question_original')
            ->update(['question_original' => DB::raw('question')]);

        DB::table('ai_support_knowledge_chunks')
            ->whereNull('answer_original')
            ->update(['answer_original' => DB::raw('answer')]);
    }

    public function down(): void
    {
        Schema::table('ai_support_knowledge_chunks', function (Blueprint $table): void {
            foreach ([
                'answer_ru_manually_edited',
                'question_ru_manually_edited',
                'answer_translated_at',
                'question_translated_at',
                'answer_translation_error',
                'question_translation_error',
                'answer_translation_provider',
                'question_translation_provider',
                'answer_translation_status',
                'question_translation_status',
                'target_locale',
                'source_locale',
                'ai_instruction',
                'answer_ru',
                'answer_original',
                'question_ru',
                'question_original',
            ] as $column) {
                if (Schema::hasColumn('ai_support_knowledge_chunks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
