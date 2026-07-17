<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('external_sources', function (Blueprint $table) {
            // Low-privilege public key for widget gateway access.
            // Distinct from the bearer token in external_source_access_tokens.
            $table->string('public_key')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('external_sources', 'external_sources_public_key_unique')) {
            Schema::table('external_sources', function (Blueprint $table): void {
                $table->dropUnique('external_sources_public_key_unique');
            });
        }

        Schema::table('external_sources', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
    }
};
