<?php

namespace Tests\Unit\Modules\Api\Services;

use App\Modules\Api\Exceptions\FileProxyException;
use App\Modules\Api\Services\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Mocks\StreamingFileService;
use Tests\TestCase;

/** Regression coverage for streaming Telegram file proxies. */
class FileServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileService $service;

    private string $tgToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgToken = '9MUF3Q6Bq88kFBN1';
        app(\App\Services\Settings\SettingsService::class)->set('telegram.token', $this->tgToken);
        $this->service = new StreamingFileService();
        Http::preventStrayRequests();
    }

    /** Full files use a SOCKS-capable cURL sink, without a disk spool. */
    public function test_download_file_streams_full_body_without_a_temporary_file(): void
    {
        $temporaryFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: [];
        $sink = null;
        config()->set('traffic_source.telegram.proxy', 'socks5h://proxy.example.test:1080');
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'images/picture.jpg', 'file_size' => 13],
            ]),
            '*/images/picture.jpg' => function ($request, array $options) use (&$sink) {
                $this->assertFalse($options['stream'] ?? false);
                $this->assertSame(0, $options['timeout']);
                $this->assertSame(3, $options['connect_timeout']);
                $this->assertSame(1024, $options['curl'][CURLOPT_LOW_SPEED_LIMIT]);
                $this->assertSame(30, $options['curl'][CURLOPT_LOW_SPEED_TIME]);
                $this->assertSame('socks5h://proxy.example.test:1080', $options['proxy']);
                $this->assertIsResource($options['sink']);
                $sink = $options['sink'];
                $this->assertArrayNotHasKey('progress', $options);

                // Client headers come from metadata, not upstream headers.
                return Http::response('IMAGE_CONTENT', 200, ['Content-Length' => '999']);
            },
        ]);

        $response = $this->service->downloadFile('456');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame('13', $response->headers->get('Content-Length'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="picture.jpg"', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        Http::assertSentCount(1);

        $this->assertSame('IMAGE_CONTENT', $this->streamedContent($response));
        $this->assertNotNull($sink);
        $this->assertFalse(is_resource($sink));
        $this->assertSame($temporaryFilesBefore, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: []);
    }

    /** Oversized metadata rejects the file before any body request is opened. */
    public function test_rejects_file_larger_than_telegram_download_limit_before_download(): void
    {
        Http::fake(['*/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'large.zip', 'file_size' => 20 * 1024 * 1024 + 1],
        ])]);
        try {
            $this->service->streamFile('large');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame('file_too_large', $e->errorCode);
            $this->assertSame(413, $e->status);
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/file/bot'));
    }

    /** Single byte ranges are normalized using metadata before downloading. */
    #[DataProvider('rangeProvider')]
    public function test_calculates_ranges_before_streaming(?string $range, int $status, ?string $upstreamRange, ?string $contentRange, int $length): void
    {
        $body = str_repeat('v', $length);
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000]]),
            '*/video.mp4' => Http::response($body),
        ]);
        $response = $this->service->streamFile('range-file', 'inline', $range);

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame((string) $length, $response->headers->get('Content-Length'));
        $this->assertSame($contentRange, $response->headers->get('Content-Range'));
        Http::assertSentCount(1);
        $this->assertSame($body, $this->streamedContent($response));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/file/bot')
            && ($upstreamRange !== null ? $request->hasHeader('Range', $upstreamRange) : !$request->hasHeader('Range')));
    }

    /** @return array<string, array{0: ?string, 1: int, 2: ?string, 3: ?string, 4: int}> */
    public static function rangeProvider(): array
    {
        return [
            'no range' => [null, 200, null, null, 1000],
            'bounded' => ['bytes=0-99', 206, 'bytes=0-99', 'bytes 0-99/1000', 100],
            'open ended' => ['bytes=100-', 206, 'bytes=100-999', 'bytes 100-999/1000', 900],
            'suffix' => ['bytes=-100', 206, 'bytes=900-999', 'bytes 900-999/1000', 100],
            'oversized suffix' => ['bytes=-2000', 206, 'bytes=0-999', 'bytes 0-999/1000', 1000],
            'clamped end' => ['bytes=900-2000', 206, 'bytes=900-999', 'bytes 900-999/1000', 100],
            'leading zeros' => ['bytes=0000-0099', 206, 'bytes=0-99', 'bytes 0-99/1000', 100],
            'multiple' => ['bytes=0-99,200-299', 200, null, null, 1000],
            'garbage' => ['not-a-range', 200, null, null, 1000],
            'empty' => ['bytes=-', 200, null, null, 1000],
            'wrong unit' => ['items=0-99', 200, null, null, 1000],
            'reversed' => ['bytes=99-0', 200, null, null, 1000],
            'zero suffix' => ['bytes=-0', 200, null, null, 1000],
            'overflow start' => ['bytes=999999999999999999999999-', 200, null, null, 1000],
            'overflow end' => ['bytes=0-999999999999999999999999', 200, null, null, 1000],
            'overflow suffix' => ['bytes=-999999999999999999999999', 200, null, null, 1000],
        ];
    }

    /** Unsatisfiable ranges never open the Telegram download. */
    public function test_returns_416_without_downloading(): void
    {
        Http::fake(['*/getFile*' => Http::response([
            'ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000],
        ])]);
        foreach (['bytes=2000-', 'bytes=1000-2000'] as $range) {
            $response = $this->service->streamFile('range-file', 'inline', $range);
            $this->assertSame(416, $response->getStatusCode());
            $this->assertSame('bytes */1000', $response->headers->get('Content-Range'));
            $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
            $this->assertSame('', $this->streamedContent($response));
        }
        Http::assertSentCount(1);
    }

    /** Missing or invalid size falls back to a full response with no length. */
    #[DataProvider('unknownSizeProvider')]
    public function test_ignores_range_when_size_is_unknown(mixed $size): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'unknown.mp4', 'file_size' => $size]]),
            '*/unknown.mp4' => Http::response('UNKNOWN_BODY'),
        ]);
        $response = $this->service->streamFile('unknown-file', 'inline', 'bytes=0-99');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($response->headers->get('Content-Length'));
        $this->assertNull($response->headers->get('Content-Range'));
        $this->assertSame('UNKNOWN_BODY', $this->streamedContent($response));
        Http::assertNotSent(fn ($request): bool => $request->hasHeader('Range'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unknownSizeProvider(): array
    {
        return ['missing' => [null], 'invalid' => ['unknown']];
    }

    /** Body-size mismatches are left to the client, without buffering or retry. */
    #[DataProvider('mismatchedBodyProvider')]
    public function test_streams_the_upstream_body_without_recalculating_length(string $body): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'mismatch.mp4', 'file_size' => 3]]),
            '*/mismatch.mp4' => Http::response($body),
        ]);
        $response = $this->service->streamFile('mismatch-file');

        $this->assertSame('3', $response->headers->get('Content-Length'));
        $this->assertSame($body, $this->streamedContent($response));
        Http::assertSentCount(2);
    }

    /** @return array<string, array{0: string}> */
    public static function mismatchedBodyProvider(): array
    {
        return ['shorter' => ['v'], 'longer' => ['video']];
    }

    /** Empty files have no satisfiable byte range. */
    public function test_range_on_empty_file_returns_416(): void
    {
        Http::fake(['*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'empty.mp4', 'file_size' => 0]])]);
        $response = $this->service->streamFile('empty-file', 'inline', 'bytes=-100');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */0', $response->headers->get('Content-Range'));
        $this->assertSame('', $this->streamedContent($response));
        Http::assertSentCount(1);
    }

    /** downloadFile preserves its attachment disposition when forwarding Range. */
    public function test_download_file_forwards_range_with_attachment_disposition(): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000]]),
            '*/video.mp4' => Http::response('v'),
        ]);
        $response = $this->service->downloadFile('range-download', 'bytes=0-0');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('attachment; filename="video.mp4"', $response->headers->get('Content-Disposition'));
        $this->assertSame('v', $this->streamedContent($response));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/file/bot') && $request->hasHeader('Range', 'bytes=0-0'));
    }

    /** Metadata is shared across Range requests for ten minutes. */
    public function test_caches_successful_metadata_for_ten_minutes(): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'cached.mp4', 'file_size' => 1000]]),
            '*/cached.mp4' => fn () => Http::response('v'),
        ]);
        foreach (['bytes=0-0', 'bytes=1-1'] as $range) {
            $this->assertSame('v', $this->streamedContent($this->service->streamFile('cached-file', 'inline', $range)));
        }
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), '/getFile')));
        $this->travel(601)->seconds();
        $this->service->getTelegramFile('cached-file');
        $this->assertCount(2, Http::recorded(fn ($request): bool => str_contains($request->url(), '/getFile')));
        $this->travelBack();
    }

    /** Cache entries must neither reveal tokens nor cross token boundaries. */
    public function test_metadata_cache_is_scoped_to_a_hashed_token_and_file_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['file_path' => 'cached.mp4', 'file_size' => 1]])]);
        $this->service->getTelegramFile('cached-file');
        $cachedKeys = array_keys(Cache::store()->getStore()->all());
        $this->assertStringNotContainsString($this->tgToken, implode('|', $cachedKeys));
        app(\App\Services\Settings\SettingsService::class)->set('telegram.token', 'another-test-token');
        (new FileService())->getTelegramFile('cached-file');
        $this->service->getTelegramFile('different-file');
        Http::assertSentCount(3);
    }

    /** Bot API failures are not cached; a later lookup may succeed. */
    public function test_does_not_cache_failed_metadata(): void
    {
        Http::fake(['*' => Http::sequence()->push(['ok' => false])->push(['ok' => true, 'result' => ['file_path' => 'recovered.mp4']])]);
        try {
            $this->service->getTelegramFile('recovering-file');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame('recovered.mp4', $this->service->getTelegramFile('recovering-file')['result']['file_path']);
        Http::assertSentCount(2);
    }

    /** Late HTTP failures log only safe identifiers without changing headers. */
    #[DataProvider('upstreamStatusProvider')]
    public function test_logs_download_status_failures_without_rethrowing(int $telegramStatus, string $code, int $status): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000]]),
            '*/video.mp4' => Http::response('', $telegramStatus),
        ]);
        $this->expectSafeStreamWarning($code);
        $response = $this->service->streamFile('failed-download');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $this->streamedContent($response));
    }

    /** Late connection failures cannot leak a token-bearing URL. */
    public function test_logs_download_transport_failure_without_rethrowing(): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000]]),
            '*/video.mp4' => Http::failedConnection('https://api.telegram.org/file/bot' . $this->tgToken),
        ]);
        $this->expectSafeStreamWarning('upstream_timeout');
        $response = $this->service->streamFile('timed-out-download');
        $this->assertSame('', $this->streamedContent($response));
    }

    /** Unexpected late errors are reduced to a safe error code. */
    public function test_logs_unexpected_download_failure_without_sensitive_details(): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'video.mp4', 'file_size' => 1000]]),
            '*/video.mp4' => function () {
                throw new \RuntimeException('Sensitive upstream URL: ' . $this->tgToken);
            },
        ]);
        $this->expectSafeStreamWarning('upstream_error');
        $this->assertSame('', $this->streamedContent($this->service->streamFile('broken-file')));
    }

    /** Token-bearing HTTP requests must not be captured by Telescope. */
    public function test_disables_telescope_only_while_requesting_telegram(): void
    {
        $wasRecording = Telescope::isRecording();
        Telescope::startRecording(false);
        Http::fake(function ($request) {
            $this->assertFalse(Telescope::isRecording());

            return str_contains($request->url(), '/getFile')
                ? Http::response(['ok' => true, 'result' => ['file_path' => 'private.mp4', 'file_size' => 1]])
                : Http::response('v');
        });
        try {
            $response = $this->service->streamFile('private-file');
            $this->assertTrue(Telescope::isRecording());
            $this->assertSame('v', $this->streamedContent($response));
            $this->assertTrue(Telescope::isRecording());
            Http::assertSentCount(2);
        } finally {
            if (!$wasRecording) {
                Telescope::stopRecording();
            }
        }
    }

    /** Metadata HTTP failures retain their existing public error codes. */
    #[DataProvider('upstreamStatusProvider')]
    public function test_maps_upstream_statuses_to_safe_errors(int $telegramStatus, string $code, int $status): void
    {
        Http::fake(['*' => Http::response([], $telegramStatus)]);
        try {
            $this->service->getTelegramFile('file');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);
        }
    }

    /** @return array<string, array{0: int, 1: string, 2: int}> */
    public static function upstreamStatusProvider(): array
    {
        return [
            'not found' => [404, 'file_not_found', 404],
            'rate limited' => [429, 'upstream_rate_limited', 429],
            'server error' => [500, 'upstream_error', 502],
        ];
    }

    /** Metadata connection failures expose only the safe timeout code. */
    public function test_maps_transport_failure_to_gateway_timeout(): void
    {
        Http::fake(['*' => Http::failedConnection('contains-sensitive-upstream-details')]);
        try {
            $this->service->getTelegramFile('file');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame('upstream_timeout', $e->errorCode);
            $this->assertSame(504, $e->status);
            $this->assertSame('upstream_timeout', $e->getMessage());
        }
    }

    /** Capture the bytes emitted by the HTTP sink through the stream callback. */
    private function streamedContent(StreamedResponse $response): string
    {
        ob_start();
        try {
            $response->sendContent();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** Require a warning with exactly a safe code and a generated trace ID. */
    private function expectSafeStreamWarning(string $code): void
    {
        Log::shouldReceive('channel')->once()->with('app')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('file_proxy_stream_failed', \Mockery::on(
            fn (array $context): bool => count($context) === 2
                && ($context['error_code'] ?? null) === $code
                && Str::isUuid($context['trace_id'] ?? ''),
        ));
    }
}
