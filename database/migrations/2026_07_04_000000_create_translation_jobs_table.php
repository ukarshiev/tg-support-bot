<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('translation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('job_type', 64)->index();
            $table->string('subject_type', 128)->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('subject_label', 255)->nullable();
            $table->string('source_locale', 16)->nullable()->index();
            $table->string('target_locale', 16)->nullable()->index();
            $table->string('provider', 64)->nullable()->index();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('characters')->default(0);
            $table->string('error_message', 1024)->nullable();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_jobs');
    }
};
