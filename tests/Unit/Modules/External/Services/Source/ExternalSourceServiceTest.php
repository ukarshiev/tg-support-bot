<?php

namespace Tests\Unit\Modules\External\Services\Source;

use App\Models\ExternalSource;
use App\Modules\External\DTOs\ExternalSourceDto;
use App\Modules\External\Services\Source\ExternalSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalSourceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExternalSourceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ExternalSourceService::class);
    }

    public function test_create_source_without_unrevealed_token(): void
    {
        $dto = ExternalSourceDto::from([
            'name' => 'test_source',
            'webhook_url' => 'https://example.com/hook',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->service->create($dto);

        $this->assertInstanceOf(ExternalSource::class, $result);
        $this->assertDatabaseHas('external_sources', ['name' => 'test_source']);
        $this->assertDatabaseMissing('external_source_access_tokens', ['external_source_id' => $result->id]);
    }

    public function test_update_source_updates_webhook_url(): void
    {
        $source = $this->service->create(ExternalSourceDto::from([
            'name' => 'test_source',
            'webhook_url' => 'https://example.com/hook',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));

        $result = $this->service->update(ExternalSourceDto::from([
            'id' => $source->id,
            'name' => 'test_source',
            'webhook_url' => 'https://example.com/hook-updated',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]));

        $this->assertInstanceOf(ExternalSource::class, $result);
        $this->assertDatabaseHas('external_sources', ['webhook_url' => 'https://example.com/hook-updated']);
        $this->assertDatabaseMissing('external_source_access_tokens', ['external_source_id' => $source->id]);
    }
}
