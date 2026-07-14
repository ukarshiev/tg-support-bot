<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_support_knowledge_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'status')) {
                $table->string('status', 32)->default('active')->after('is_active')->index();
            }

            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'moderation_reason')) {
                $table->text('moderation_reason')->nullable()->after('status');
            }

            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'moderation_risks')) {
                $table->json('moderation_risks')->nullable()->after('moderation_reason');
            }

            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'duplicate_group_key')) {
                $table->string('duplicate_group_key')->nullable()->after('moderation_risks')->index();
            }

            if (! Schema::hasColumn('ai_support_knowledge_chunks', 'source_metadata')) {
                $table->json('source_metadata')->nullable()->after('duplicate_group_key');
            }
        });

        DB::table('ai_support_knowledge_chunks')
            ->where('is_active', false)
            ->update(['status' => 'disabled']);

        DB::table('ai_support_knowledge_chunks')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '');
            })
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('ai_support_knowledge_chunks', function (Blueprint $table): void {
            foreach (['source_metadata', 'duplicate_group_key', 'moderation_risks', 'moderation_reason', 'status'] as $column) {
                if (Schema::hasColumn('ai_support_knowledge_chunks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
