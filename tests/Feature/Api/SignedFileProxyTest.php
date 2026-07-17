<?php

namespace Tests\Feature\Api;

use App\Helpers\TelegramHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignedFileProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_and_tampered_urls_are_rejected_before_telegram(): void
    {
        Http::fake();

        $this->get('/api/files/secret-file')->assertForbidden();

        $url = TelegramHelper::getFilePublicPath('secret-file');
        $this->get(str_replace('secret-file', 'changed-file', $url))->assertForbidden();
        $this->get(str_replace('disposition=inline', 'disposition=attachment', $url))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_expired_url_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-18 00:00:00');
        $url = TelegramHelper::getFilePublicPath('file-id');
        Carbon::setTestNow('2026-07-18 00:16:00');

        $this->get($url)->assertForbidden();
        Carbon::setTestNow();
    }

    public function test_valid_relative_signature_streams_file_with_safe_headers(): void
    {
        Http::fake([
            '*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'documents/test.pdf', 'file_size' => 11],
            ]),
            '*/documents/test.pdf' => Http::response('PDF_CONTENT', 200, ['Content-Length' => '11']),
        ]);

        $signedUrl = TelegramHelper::getFilePublicPath('file-id');
        $relativeUrl = parse_url($signedUrl, PHP_URL_PATH) . '?' . parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->get($relativeUrl);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('PDF_CONTENT', $response->streamedContent());
    }

    public function test_throttle_runs_before_signature_validation(): void
    {
        config()->set('file_proxy.requests_per_minute', 2);

        $this->get('/api/files/unsigned')->assertForbidden();
        $this->get('/api/files/unsigned')->assertForbidden();
        $this->get('/api/files/unsigned')->assertTooManyRequests();
    }

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

        $this->post($relativeUrl)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="test.pdf"')
            ->assertHeader('Deprecation', 'true');
    }
}
