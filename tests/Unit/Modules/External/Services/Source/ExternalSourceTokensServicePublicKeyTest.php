<?php

namespace Tests\Unit\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Modules\External\Services\Source\ExternalSourceTokensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalSourceTokensServicePublicKeyTest extends TestCase
{
    use RefreshDatabase;

    private ExternalSourceTokensService $service;

    private ExternalSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = ExternalSource::create(['name' => 'widget_test_source']);
        $this->service = app(ExternalSourceTokensService::class);
    }

    public function test_generate_public_key_has_pub_prefix(): void
    {
        $key = $this->service->generatePublicKey();

        $this->assertStringStartsWith('pub_', $key);
    }

    public function test_generate_public_key_is_40_chars(): void
    {
        $key = $this->service->generatePublicKey();

        // 'pub_' (4) + 36 random chars = 40
        $this->assertEquals(40, strlen($key));
    }

    public function test_generate_public_key_returns_different_values(): void
    {
        $key1 = $this->service->generatePublicKey();
        $key2 = $this->service->generatePublicKey();

        $this->assertNotEquals($key1, $key2);
    }

    public function test_rotate_public_key_persists_key_on_source(): void
    {
        $key = $this->service->rotatePublicKey($this->source);

        $this->assertDatabaseHas('external_sources', [
            'id' => $this->source->id,
            'public_key' => $key,
        ]);
    }

    public function test_rotate_public_key_returns_key_with_pub_prefix(): void
    {
        $key = $this->service->rotatePublicKey($this->source);

        $this->assertStringStartsWith('pub_', $key);
    }

    public function test_rotate_public_key_replaces_previous_key(): void
    {
        $first = $this->service->rotatePublicKey($this->source);
        $second = $this->service->rotatePublicKey($this->source);

        $this->assertNotEquals($first, $second);

        $this->assertDatabaseHas('external_sources', [
            'id' => $this->source->id,
            'public_key' => $second,
        ]);

        $this->assertDatabaseMissing('external_sources', [
            'id' => $this->source->id,
            'public_key' => $first,
        ]);
    }
}
