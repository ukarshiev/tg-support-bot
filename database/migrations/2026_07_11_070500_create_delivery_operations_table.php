<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('delivery_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 64)->unique();
            $table->foreignId('bot_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trace_id', 160)->index();
            $table->string('destination', 80);
            $table->string('operation', 80);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('external_message_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_operations');
    }
};
