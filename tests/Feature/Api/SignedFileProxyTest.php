<?php

namespace Tests\Feature\Api;

use App\Helpers\TelegramHelper;
use App\Modules\Api\Services\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Mocks\StreamingFileService;
use Tests\TestCase;

/** Signed file endpoints preserve authorization and upstream streaming semantics. */
class SignedFileProxyTest extends TestCase
{
    use RefreshDatabase;

    /** Preserve PHPUnit's capture buffer at the PHP output boundary only. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FileService::class, new StreamingFileService());
        Http::preventStrayRequests();
    }

    /** Invalid signatures are rejected before contacting Telegram. */
    public function test_unsigned_and_tampered_urls_are_rejected_before_telegram(): void
    {
        Http::fake();

        $this->get('/api/files/secret-file')->assertForbidden();

        $url = TelegramHelper::getFilePublicPath('secret-file');
        $this->get(str_replace('secret-file', 'changed-file', $url))->assertForbidden();
        $this->get(str_replace('disposition=inline', 'disposition=attachment', $url))->assertForbidden();

        Http::assertNothingSent();
    }

    /** Expired signed URLs cannot open file streams. */
    public function test_expired_url_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-18 00:00:00');
        $url = TelegramHelper::getFilePublicPath('file-id');
        Carbon::setTestNow('2026-07-18 00:16:00');

        $this->get($url)->assertForbidden();
        Carbon::setTestNow();
    }

    /** A valid relative signature streams the complete file with safe headers. */
    public function test_valid_relative_signature_streams_file_with_safe_headers(): void
    {
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/test.pdf', 'file_size' => 11],
            ]),
            '*/documents/test.pdf' => Http::response('PDF_CONTENT'),
        ]);

        $signedUrl = TelegramHelper::getFilePublicPath('file-id');
        $relativeUrl = parse_url($signedUrl, PHP_URL_PATH) . '?' . parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->get($relativeUrl);

        $response->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Length', '11')
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertSentCount(1);
        $this->assertSame('PDF_CONTENT', $response->streamedContent());
    }

    /** Signed Range requests preserve partial bodies and reuse Bot API metadata. */
    public function test_signed_range_requests_stream_partial_content_and_reuse_metadata(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'videos/test.mp4', 'file_size' => 1000],
            ]),
            '*/videos/test.mp4' => fn () => Http::response(str_repeat('v', 100)),
        ]);
        $url = TelegramHelper::getFilePublicPath('range-video');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->get($url, ['Range' => 'bytes=0-99']);
            $response->assertStatus(206)
                ->assertHeader('Accept-Ranges', 'bytes')
                ->assertHeader('Content-Type', 'video/mp4')
                ->assertHeader('Content-Length', '100')
                ->assertHeader('Content-Range', 'bytes 0-99/1000');
            $this->assertSame(str_repeat('v', 100), $response->streamedContent());
        }

        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), '/getFile')));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/file/bot')
            && $request->hasHeader('Range', 'bytes=0-99'));
        Http::assertSentCount(3);
    }

    /** Invalid and multipart Range headers fall back to the complete resource. */
    public function test_invalid_ranges_are_not_forwarded_by_the_controller(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'videos/test.mp4', 'file_size' => 11],
            ]),
            '*/videos/test.mp4' => fn () => Http::response('VIDEO_BYTES', 200, ['Content-Length' => '11']),
        ]);

        foreach (['garbage', 'bytes=0-1,3-4'] as $range) {
            $response = $this->get(TelegramHelper::getFilePublicPath('invalid-range-video'), ['Range' => $range]);
            $response->assertOk()->assertHeader('Accept-Ranges', 'bytes');
            $this->assertSame('VIDEO_BYTES', $response->streamedContent());
        }
        Http::assertNotSent(fn ($request): bool => $request->hasHeader('Range'));
    }

    /** Suffix ranges are converted to an explicit upstream byte range. */
    public function test_signed_suffix_range_is_normalized_before_download(): void
    {
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'test.mp4', 'file_size' => 1000]]),
            '*/test.mp4' => Http::response(str_repeat('v', 100)),
        ]);

        $response = $this->get(TelegramHelper::getFilePublicPath('suffix-video'), ['Range' => 'bytes=-100']);

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 900-999/1000')
            ->assertHeader('Content-Length', '100');
        $this->assertSame(str_repeat('v', 100), $response->streamedContent());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/file/bot')
            && $request->hasHeader('Range', 'bytes=900-999'));
    }

    /** Unsatisfiable ranges return 416 without opening the Telegram download. */
    public function test_signed_unsatisfiable_range_returns_416(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'test.mp4', 'file_size' => 11]]),
        ]);

        $response = $this->get(TelegramHelper::getFilePublicPath('short-video'), ['Range' => 'bytes=20-']);

        $response->assertStatus(416)->assertHeader('Content-Range', 'bytes */11');
        $this->assertSame('', $response->streamedContent());
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/file/bot'));
    }

    /** Oversized metadata prevents opening the upstream download even for Range. */
    public function test_oversized_signed_video_returns_413_before_download(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/getFile*' => Http::response([
            'ok' => true,
            'result' => ['file_path' => 'large.mp4', 'file_size' => 20 * 1024 * 1024 + 1],
        ])]);

        $this->get(TelegramHelper::getFilePublicPath('large-video'), ['Range' => 'bytes=0-99'])
            ->assertStatus(413)
            ->assertJsonPath('error_code', 'file_too_large');

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/file/bot'));
    }

    /** Rate limiting also protects invalid signed requests. */
    public function test_throttle_runs_before_signature_validation(): void
    {
        config()->set('file_proxy.requests_per_minute', 2);

        $this->get('/api/files/unsigned')->assertForbidden();
        $this->get('/api/files/unsigned')->assertForbidden();
        $this->get('/api/files/unsigned')->assertTooManyRequests();
    }

    /** The legacy POST preserves the signed disposition and forwards Range. */
    public function test_deprecated_post_uses_the_signed_disposition(): void
    {
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/test.pdf', 'file_size' => 11],
            ]),
            '*/documents/test.pdf' => Http::response('PDF_CONTENT', 200, ['Content-Length' => '11']),
        ]);

        $signedUrl = \App\Helpers\TelegramHelper::getFilePublicPath('file-id', 'attachment');
        $relativeUrl = parse_url($signedUrl, PHP_URL_PATH) . '?' . parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->post($relativeUrl, [], ['Range' => 'bytes=0-10']);
        $response
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-10/11')
            ->assertHeader('Content-Length', '11')
            ->assertHeader('Content-Disposition', 'attachment; filename="test.pdf"')
            ->assertHeader('Deprecation', 'true');
        $this->assertSame('PDF_CONTENT', $response->streamedContent());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/file/bot')
            && $request->hasHeader('Range', 'bytes=0-10'));
    }
}
