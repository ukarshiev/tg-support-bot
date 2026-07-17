<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('external_source_access_tokens', function (Blueprint $table): void {
            $table->string('token', 68)->nullable()->change();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('token_hint', 6)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        DB::table('external_source_access_tokens')
            ->whereNotNull('token')
            ->orderBy('id')
            ->eachById(function (object $row): void {
                $token = (string) $row->token;
                DB::table('external_source_access_tokens')
                    ->where('id', $row->id)
                    ->update([
                        'token_hash' => hash('sha256', $token),
                        'token_hint' => substr($token, -6),
                    ]);
            });

        Schema::table('external_sources', function (Blueprint $table): void {
            $table->string('webhook_key_id', 24)->nullable();
            $table->text('webhook_signing_secret')->nullable();
            $table->string('pending_webhook_key_id', 24)->nullable();
            $table->text('pending_webhook_signing_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_sources', function (Blueprint $table): void {
            $table->dropColumn([
                'webhook_key_id',
                'webhook_signing_secret',
                'pending_webhook_key_id',
                'pending_webhook_signing_secret',
            ]);
        });

        Schema::table('external_source_access_tokens', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn([
                'token_hash',
                'token_hint',
                'expires_at',
                'last_used_at',
                'revoked_at',
            ]);
            // Keep nullable on rollback: hash-only rows cannot be safely converted
            // back into plaintext tokens.
            $table->string('token', 64)->nullable()->change();
        });
    }
};
