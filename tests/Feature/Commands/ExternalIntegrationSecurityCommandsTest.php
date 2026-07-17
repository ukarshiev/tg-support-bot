<?php

namespace Tests\Feature\Commands;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExternalIntegrationSecurityCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_passes_only_without_legacy_widget_keys_or_missing_hashes(): void
    {
        $source = ExternalSource::factory()->create();
        ExternalSourceAccessTokens::create([
            'external_source_id' => $source->id,
            'token_hash' => hash('sha256', 'token'),
            'token_hint' => 'token',
            'active' => true,
        ]);

        $this->artisan('security:external-preflight')->assertSuccessful();

        DB::table('external_sources')->where('id', $source->id)->update(['public_key' => 'legacy']);
        $this->artisan('security:external-preflight')->assertFailed();
    }

    public function test_finalization_refuses_missing_hash_and_clears_only_plaintext(): void
    {
        $source = ExternalSource::factory()->create();
        DB::table('external_source_access_tokens')->insert([
            'external_source_id' => $source->id,
            'token' => 'legacy',
            'token_hash' => null,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('external-tokens:finalize --force')->assertFailed();

        DB::table('external_source_access_tokens')->update([
            'token_hash' => hash('sha256', 'legacy'),
            'token_hint' => 'legacy',
        ]);
        $this->artisan('external-tokens:finalize --force')->assertSuccessful();

        $record = ExternalSourceAccessTokens::sole();
        $this->assertNull($record->token);
        $this->assertSame(hash('sha256', 'legacy'), $record->token_hash);
    }
}
