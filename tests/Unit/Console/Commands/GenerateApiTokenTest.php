<?php

namespace Tests\Unit\Console\Commands;

use App\Models\ExternalSource;
use App\Models\ExternalSourceAccessTokens;
use App\Services\Webhook\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerateApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    private string $url;

    private ExternalSource $sourceModel;

    public function setUp(): void
    {
        parent::setUp();

        $this->mock(DnsResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->with('example.com')
                ->andReturn(['93.184.216.34']);
        });

        $this->source = 'phpunit-source';
        $this->url = 'https://example.com/hook';

        if (ExternalSource::where(['name' => $this->source])->exists()) {
            ExternalSource::where(['name' => $this->source])->delete();
        }

        $this->sourceModel = ExternalSource::create(['name' => $this->source]);
    }

    public function test_successful_token_generation(): void
    {
        $legacyToken = Str::random(32);
        ExternalSourceAccessTokens::create([
            'external_source_id' => $this->sourceModel->id,
            'token' => $legacyToken,
        ]);

        // создание токена
        $exitCode = Artisan::call('app:generate-token', [
            'source' => $this->source,
            'hook_url' => $this->url,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertDatabaseHas('external_sources', ['name' => $this->source]);
        $this->assertStringNotContainsString($legacyToken, Artisan::output());

        $newToken = ExternalSourceAccessTokens::latest('id')->firstOrFail();
        $this->assertNull($newToken->token);
        $this->assertNotNull($newToken->token_hash);

        // обновление токена
        $exitCode = Artisan::call('app:generate-token', [
            'source' => $this->source,
            'hook_url' => $this->url,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertDatabaseHas('external_sources', ['name' => $this->source]);
    }

    public function test_invalid_url(): void
    {
        $exitCode = Artisan::call('app:generate-token', [
            'source' => 'invalid-url-source',
            'hook_url' => 'invalid-url',
        ]);

        $this->assertEquals(1, $exitCode);
    }
}
