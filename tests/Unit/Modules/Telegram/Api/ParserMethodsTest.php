<?php

namespace Tests\Unit\Modules\Telegram\Api;

use App\Modules\Telegram\Api\ParserMethods;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ParserMethodsTest extends TestCase
{
    private string $url;

    public function setUp(): void
    {
        parent::setUp();

        $this->url = 'https://example.com/api';
    }

    public function test_post_query_success(): void
    {
        Http::fake([
            $this->url => Http::response(['ok' => true, 'result' => 'Success'], 200),
        ]);

        $response = ParserMethods::postQuery($this->url, ['ok' => true, 'result' => 'Success'], ['Header' => 'value']);

        $this->assertTrue($response['ok']);
        $this->assertEquals('Success', $response['result']);
    }

    public function test_post_query_failure(): void
    {
        Http::fake([
            $this->url => Http::response([], 500),
        ]);

        $response = ParserMethods::postQuery($this->url, ['param' => 'value'], ['Header' => 'value']);

        $this->assertFalse($response['ok']);
        $this->assertEquals('Request caused an error', $response['result']);
    }

    public function test_get_query_success(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'result' => 'Success'], 200),
        ]);

        $response = ParserMethods::getQuery($this->url, ['ok' => true, 'result' => 'Success'], ['Header' => 'value']);

        $this->assertTrue($response['ok']);
        $this->assertEquals('Success', $response['result']);
    }

    public function test_get_query_failure(): void
    {
        Http::fake([
            $this->url => Http::response([], 500),
        ]);

        $response = ParserMethods::getQuery($this->url, ['param' => 'value'], ['Header' => 'value']);

        $this->assertFalse($response['ok']);
        $this->assertEquals('Request caused an error', $response['result']);
    }

    public function test_attach_query_with_valid_file(): void
    {
        Http::fake([
            $this->url => Http::response(['ok' => true, 'result' => 'file uploaded'], 200),
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 1024); // 1 KB

        $response = ParserMethods::attachQuery($this->url, [
            'uploaded_file' => $file,
        ]);

        $this->assertTrue($response['ok']);
        $this->assertEquals('file uploaded', $response['result']);
    }

    public function test_attach_query_throws_exception_on_empty_file(): void
    {
        $file = UploadedFile::fake()->create('empty.pdf', 0);

        $response = ParserMethods::attachQuery($this->url, [
            'uploaded_file' => $file,
        ]);

        $this->assertFalse($response['ok']);
        $this->assertEquals(500, $response['response_code']);
        $this->assertStringContainsString('File is empty and cannot be sent', $response['result']);
    }

    public function test_empty_proxy_does_not_add_guzzle_proxy_option(): void
    {
        config()->set('traffic_source.telegram.proxy', '');

        Http::fake(function ($request, array $options) {
            $this->assertArrayNotHasKey('proxy', $options);

            return Http::response(['ok' => true, 'result' => 'direct']);
        });

        $response = ParserMethods::postQuery('https://api.telegram.org/bot123/sendMessage');

        $this->assertSame('direct', $response['result']);
    }

    public function test_http_proxy_is_added_to_telegram_get_request(): void
    {
        $proxy = 'http://host.docker.internal:10809';
        config()->set('traffic_source.telegram.proxy', $proxy);

        Http::fake(function ($request, array $options) use ($proxy) {
            $this->assertSame($proxy, $options['proxy'] ?? null);

            return Http::response(['ok' => true, 'result' => 'proxied']);
        });

        $response = ParserMethods::getQuery('https://api.telegram.org/bot123/getMe');

        $this->assertSame('proxied', $response['result']);
    }

    public function test_socks5h_proxy_is_added_to_telegram_attachment_request(): void
    {
        $proxy = 'socks5h://host.docker.internal:10808';
        config()->set('traffic_source.telegram.proxy', $proxy);

        Http::fake(function ($request, array $options) use ($proxy) {
            $this->assertSame($proxy, $options['proxy'] ?? null);

            return Http::response(['ok' => true, 'result' => 'uploaded']);
        });

        $response = ParserMethods::attachQuery(
            'https://api.telegram.org/bot123/sendDocument',
            ['uploaded_file' => UploadedFile::fake()->create('document.pdf', 1)],
        );

        $this->assertSame('uploaded', $response['result']);
    }

    public function test_proxy_is_not_added_to_non_telegram_request(): void
    {
        config()->set('traffic_source.telegram.proxy', 'http://host.docker.internal:10809');

        Http::fake(function ($request, array $options) {
            $this->assertArrayNotHasKey('proxy', $options);

            return Http::response(['ok' => true, 'result' => 'internal']);
        });

        $response = ParserMethods::postQuery('http://nginx/internal');

        $this->assertSame('internal', $response['result']);
    }

    public function test_proxy_credentials_are_masked_in_transport_log(): void
    {
        Log::shouldReceive('channel')->with('app')->andReturnSelf();
        Log::shouldReceive('log')->once()->withArgs(function (string $level, string $message, array $context): bool {
            return $level === 'error'
                && $message === 'Telegram transport failed'
                && str_contains($context['error'], 'http://[hidden]@proxy.local:10809')
                && ! str_contains($context['error'], 'proxy-user')
                && ! str_contains($context['error'], 'proxy-pass');
        });

        Http::fake(function (): never {
            throw new \RuntimeException('Proxy http://proxy-user:proxy-pass@proxy.local:10809 failed');
        });

        $response = ParserMethods::postQuery('https://api.telegram.org/bot123/sendMessage');

        $this->assertFalse($response['ok']);
    }
}
