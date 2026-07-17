<?php

namespace Tests\Feature\Middleware;

use App\Modules\Max\Middleware\MaxQuery;
use App\Modules\Vk\Middleware\VkQuery;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PlatformWebhookAuthenticationTest extends TestCase
{
    public function test_vk_returns_503_when_secret_is_not_configured(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('vk.secret_key')->once()->andReturn('');
        $this->app->instance(SettingsService::class, $settings);

        $response = (new VkQuery())->handle(Request::create('/api/vk/bot', 'POST', ['secret' => 'anything']), fn () => response('ok'));

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function test_vk_rejects_wrong_secret_and_wrong_group_including_confirmation(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('vk.secret_key')->andReturn('correct-secret');
        $settings->shouldReceive('get')->with('vk.group_id')->andReturn(123);
        $this->app->instance(SettingsService::class, $settings);

        $wrongSecret = Request::create('/api/vk/bot', 'POST', ['secret' => 'wrong', 'group_id' => 123]);
        $wrongGroup = Request::create('/api/vk/bot', 'POST', [
            'type' => 'confirmation',
            'secret' => 'correct-secret',
            'group_id' => 999,
        ]);

        $this->assertSame(403, (new VkQuery())->handle($wrongSecret, fn () => response('ok'))->getStatusCode());
        $this->assertSame(403, (new VkQuery())->handle($wrongGroup, fn () => response('ok'))->getStatusCode());
    }

    public function test_vk_accepts_matching_secret_and_group(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('vk.secret_key')->andReturn('correct-secret');
        $settings->shouldReceive('get')->with('vk.group_id')->andReturn(123);
        $this->app->instance(SettingsService::class, $settings);
        $request = Request::create('/api/vk/bot', 'POST', ['secret' => 'correct-secret', 'group_id' => 123]);

        $this->assertSame('ok', (new VkQuery())->handle($request, fn () => response('ok'))->getContent());
    }

    public function test_max_fails_closed_and_uses_generic_denial(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('max.secret_key')->andReturn('correct-secret');
        $this->app->instance(SettingsService::class, $settings);
        $request = Request::create('/api/max/bot', 'POST');
        $request->headers->set('X-Max-Bot-Api-Secret', 'wrong');

        $response = (new MaxQuery())->handle($request, fn () => response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Access is forbidden'], json_decode($response->getContent(), true));
    }

    public function test_max_returns_503_when_secret_is_not_configured(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('max.secret_key')->once()->andReturn('');
        $this->app->instance(SettingsService::class, $settings);

        $response = (new MaxQuery())->handle(Request::create('/api/max/bot', 'POST'), fn () => response('ok'));

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function test_max_accepts_matching_secret(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('max.secret_key')->andReturn('correct-secret');
        $this->app->instance(SettingsService::class, $settings);
        $request = Request::create('/api/max/bot', 'POST');
        $request->headers->set('X-Max-Bot-Api-Secret', 'correct-secret');

        $this->assertSame('ok', (new MaxQuery())->handle($request, fn () => response('ok'))->getContent());
    }
}
