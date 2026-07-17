<?php

namespace Tests\Unit\Modules\Api\Exceptions;

use App\Modules\Api\Exceptions\FileProxyException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class FileProxyExceptionTest extends TestCase
{
    public function test_report_contains_only_safe_code_and_trace_id(): void
    {
        $logger = Mockery::mock(Logger::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('file_proxy_failed', Mockery::on(function (array $context): bool {
                $this->assertSame(['error_code', 'trace_id'], array_keys($context));
                $this->assertSame('upstream_error', $context['error_code']);
                $this->assertNotEmpty($context['trace_id']);

                return true;
            }));
        Log::shouldReceive('channel')->once()->with('app')->andReturn($logger);

        $exception = new FileProxyException(
            'upstream_error',
            502,
            new \RuntimeException('bot-token file-id signature payload'),
        );

        $this->assertTrue($exception->report());
    }
}
