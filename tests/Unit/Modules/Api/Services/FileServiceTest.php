<?php

namespace Tests\Unit\Modules\Api\Services;

use App\Modules\Api\Exceptions\FileProxyException;
use App\Modules\Api\Services\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

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
        $this->service = new FileService();
    }

    public function test_download_file_is_streamed_and_temporary_file_is_removed(): void
    {
        $temporaryFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: [];

        Http::fake([
            'https://api.telegram.org/bot' . $this->tgToken . '/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'images/picture.jpg', 'file_size' => 13],
            ]),
            'https://api.telegram.org/file/bot' . $this->tgToken . '/images/picture.jpg' => Http::response('IMAGE_CONTENT'),
        ]);

        $response = $this->service->downloadFile('456');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="picture.jpg"', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        ob_start();
        $response->sendContent();
        $this->assertSame('IMAGE_CONTENT', ob_get_clean());
        $this->assertSame($temporaryFilesBefore, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: []);
    }

    public function test_rejects_file_larger_than_telegram_download_limit_before_download(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'large.zip', 'file_size' => 20 * 1024 * 1024 + 1],
            ]),
        ]);

        try {
            $this->service->streamFile('large');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame('file_too_large', $e->errorCode);
            $this->assertSame(413, $e->status);
        }

        Http::assertSentCount(1);
    }

    public function test_aborts_transfer_as_soon_as_stream_exceeds_limit(): void
    {
        $temporaryFilesBefore = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: [];
        $maxBytes = (int) config('file_proxy.max_bytes');

        Http::fake([
            'https://api.telegram.org/bot' . $this->tgToken . '/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'unknown-size.bin'],
            ]),
            'https://api.telegram.org/file/bot' . $this->tgToken . '/unknown-size.bin' => function ($request, array $options) use ($maxBytes) {
                $options['progress']($maxBytes + 1, $maxBytes + 1, 0, 0);

                return Http::response('must-not-complete');
            },
        ]);

        try {
            $this->service->streamFile('unknown-size');
            $this->fail('Expected FileProxyException.');
        } catch (FileProxyException $e) {
            $this->assertSame('file_too_large', $e->errorCode);
            $this->assertSame(413, $e->status);
        }

        $this->assertSame($temporaryFilesBefore, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tg-file-*') ?: []);
    }

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

    public static function upstreamStatusProvider(): array
    {
        return [
            'not found' => [404, 'file_not_found', 404],
            'rate limited' => [429, 'upstream_rate_limited', 429],
            'server error' => [500, 'upstream_error', 502],
        ];
    }

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
}
