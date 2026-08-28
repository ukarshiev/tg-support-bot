<?php

namespace Tests\Feature\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramPollUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('telegram:poller:offset');
        Cache::forget('telegram:ai-poller:offset');
    }

    public function test_main_poller_does_not_crash_when_get_updates_transport_fails_once(): void
    {
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.secret_key', 'main-secret');

        Http::fake([
            'https://api.telegram.org/botmain-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]], 200),
            'https://api.telegram.org/botmain-token/deleteWebhook' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/botmain-token/getUpdates' => function (): never {
                throw new \RuntimeException('Connection timed out for https://api.telegram.org/botmain-token/getUpdates');
            },
        ]);

        $this->artisan('telegram:poll-updates', [
            '--once' => true,
            '--timeout' => 1,
            '--sleep' => 1,
        ])->assertSuccessful();
    }

    public function test_main_poller_forwards_callback_update_to_internal_webhook(): void
    {
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.secret_key', 'main-secret');

        Http::fake([
            'https://api.telegram.org/botmain-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]], 200),
            'https://api.telegram.org/botmain-token/deleteWebhook' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/botmain-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [
                    [
                        'update_id' => 200,
                        'callback_query' => [
                            'id' => 'callback-main-1',
                            'data' => 'select_language:pl',
                            'from' => [
                                'id' => 555001,
                                'is_bot' => false,
                                'first_name' => 'Client',
                            ],
                            'message' => [
                                'message_id' => 10,
                                'chat' => [
                                    'id' => 555001,
                                    'type' => 'private',
                                ],
                                'text' => "Выберите язык / Choose your language:\nСтраница 1/2",
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://nginx/api/telegram/bot' => Http::response(null, 204),
        ]);

        $this->artisan('telegram:poll-updates', [
            '--once' => true,
            '--timeout' => 1,
            '--sleep' => 1,
        ])->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://nginx/api/telegram/bot'
                && $request->hasHeader('X-Telegram-Bot-Api-Secret-Token', 'main-secret')
                && $request['update_id'] === 200
                && $request['callback_query']['data'] === 'select_language:pl';
        });

        $this->assertSame(201, Cache::get('telegram:poller:offset'));
    }

    public function test_main_poller_advances_offset_after_poison_update_exhausts_retries(): void
    {
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.secret_key', 'main-secret');
        $webhookAttempts = 0;

        Log::shouldReceive('channel')->with('app')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'Telegram poller: poisoned update skipped after webhook rejections'
                && $context['source'] === 'telegram_poller_poisoned_update'
                && $context['update_id'] === 410
                && $context['status'] === 422;
        });

        Http::fake([
            'https://api.telegram.org/botmain-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]]),
            'https://api.telegram.org/botmain-token/deleteWebhook' => Http::response(['ok' => true]),
            'https://api.telegram.org/botmain-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [['update_id' => 410, 'message' => ['text' => 'broken']]],
            ]),
            'http://nginx/api/telegram/bot' => function () use (&$webhookAttempts) {
                $webhookAttempts++;

                return Http::response(['error' => 'validation'], 422);
            },
        ]);

        $this->artisan('telegram:poll-updates', ['--once' => true, '--timeout' => 1])->assertSuccessful();

        $this->assertSame(411, Cache::get('telegram:poller:offset'));
        $this->assertSame(3, $webhookAttempts);
    }

    public function test_main_poller_does_not_advance_offset_when_internal_webhook_transport_fails(): void
    {
        app(SettingsService::class)->set('telegram.token', 'main-token');
        app(SettingsService::class)->set('telegram.secret_key', 'main-secret');
        Cache::forever('telegram:poller:offset', 420);

        Http::fake([
            'https://api.telegram.org/botmain-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1]]),
            'https://api.telegram.org/botmain-token/deleteWebhook' => Http::response(['ok' => true]),
            'https://api.telegram.org/botmain-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [['update_id' => 420, 'message' => ['text' => 'retry later']]],
            ]),
            'http://nginx/api/telegram/bot' => function (): never {
                throw new \RuntimeException('Internal webhook connection refused');
            },
        ]);

        $this->artisan('telegram:poll-updates', ['--once' => true, '--timeout' => 1])->assertSuccessful();

        $this->assertSame(420, Cache::get('telegram:poller:offset'));
    }

    public function test_ai_poller_persists_offset_only_after_successful_internal_delivery(): void
    {
        app(SettingsService::class)->set('telegram_ai.token', 'ai-token');
        app(SettingsService::class)->set('telegram_ai.secret', 'ai-secret');

        Http::fake([
            'https://api.telegram.org/botai-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 2]]),
            'https://api.telegram.org/botai-token/deleteWebhook' => Http::response(['ok' => true]),
            'https://api.telegram.org/botai-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [[
                    'update_id' => 501,
                    'callback_query' => ['id' => 'ai-callback', 'data' => 'accept:1'],
                ]],
            ]),
            'http://nginx/api/ai-bot/webhook' => Http::response(null, 204),
        ]);

        $this->artisan('ai-bot:poll-updates', ['--once' => true, '--timeout' => 1])->assertSuccessful();

        $this->assertSame(502, Cache::get('telegram:ai-poller:offset'));
    }

    public function test_ai_poller_reuses_persisted_offset_and_keeps_it_on_internal_4xx(): void
    {
        app(SettingsService::class)->set('telegram_ai.token', 'ai-token');
        app(SettingsService::class)->set('telegram_ai.secret', 'ai-secret');
        Cache::forever('telegram:ai-poller:offset', 700);

        Http::fake([
            'https://api.telegram.org/botai-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 2]]),
            'https://api.telegram.org/botai-token/deleteWebhook' => Http::response(['ok' => true]),
            'https://api.telegram.org/botai-token/getUpdates' => function ($request) {
                $this->assertSame(700, $request['offset']);

                return Http::response([
                    'ok' => true,
                    'result' => [['update_id' => 700, 'callback_query' => ['id' => 'bad']]],
                ]);
            },
            'http://nginx/api/ai-bot/webhook' => Http::response(['error' => 'bad callback'], 400),
        ]);

        $startedAt = microtime(true);
        $this->artisan('ai-bot:poll-updates', ['--once' => true, '--timeout' => 1])->assertSuccessful();

        $this->assertSame(700, Cache::get('telegram:ai-poller:offset'));
        $this->assertGreaterThanOrEqual(0.8, microtime(true) - $startedAt, 'AI poller обязан выдерживать паузу при отказе внутреннего webhook.');
    }

    public function test_ai_poller_catches_get_updates_transport_failure_without_losing_offset(): void
    {
        app(SettingsService::class)->set('telegram_ai.token', 'ai-token');
        app(SettingsService::class)->set('telegram_ai.secret', 'ai-secret');
        Cache::forever('telegram:ai-poller:offset', 900);

        Http::fake([
            'https://api.telegram.org/botai-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 2]]),
            'https://api.telegram.org/botai-token/deleteWebhook' => Http::response(['ok' => true]),
            'https://api.telegram.org/botai-token/getUpdates' => function (): never {
                throw new \RuntimeException('timeout for https://api.telegram.org/bot123:secret/getUpdates');
            },
        ]);

        $this->artisan('ai-bot:poll-updates', ['--once' => true, '--timeout' => 1])->assertSuccessful();

        $this->assertSame(900, Cache::get('telegram:ai-poller:offset'));
    }

    public function test_main_poller_fails_preflight_for_revoked_token_without_polling(): void
    {
        app(SettingsService::class)->set('telegram.token', 'revoked-token');
        app(SettingsService::class)->set('telegram.secret_key', 'main-secret');

        Http::fake([
            'https://api.telegram.org/botrevoked-token/getMe' => Http::response([
                'ok' => false,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->artisan('telegram:poll-updates', ['--once' => true])->assertFailed();

        Http::assertSentCount(1);
        $this->assertFalse(app(\App\Support\TelegramPollingRuntime::class)->isHealthy('main', 90));
    }

    public function test_ai_poller_fails_preflight_for_missing_bot_without_polling(): void
    {
        app(SettingsService::class)->set('telegram_ai.token', 'missing-token');
        app(SettingsService::class)->set('telegram_ai.secret', 'ai-secret');

        Http::fake([
            'https://api.telegram.org/botmissing-token/getMe' => Http::response([
                'ok' => false,
                'description' => 'Not Found',
            ], 404),
        ]);

        $this->artisan('ai-bot:poll-updates', ['--once' => true])->assertFailed();

        Http::assertSentCount(1);
        $this->assertFalse(app(\App\Support\TelegramPollingRuntime::class)->isHealthy('ai', 90));
    }
}
