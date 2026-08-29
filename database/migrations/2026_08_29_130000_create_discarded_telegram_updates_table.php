<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('discarded_telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->json('payload');
            $table->unsignedSmallInteger('http_status');
            $table->unsignedInteger('attempts');
            $table->timestamp('discarded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discarded_telegram_updates');
    }
};
