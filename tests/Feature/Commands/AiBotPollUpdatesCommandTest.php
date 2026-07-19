<?php

namespace Tests\Feature\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiBotPollUpdatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_bot_poller_deletes_webhook_and_forwards_callback_to_internal_webhook(): void
    {
        app(SettingsService::class)->set('telegram_ai.token', 'ai-token');
        app(SettingsService::class)->set('telegram_ai.secret', 'ai-secret');

        Http::fake([
            'https://api.telegram.org/botai-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 2]], 200),
            'https://api.telegram.org/botai-token/deleteWebhook' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/botai-token/getUpdates' => Http::response([
                'ok' => true,
                'result' => [
                    [
                        'update_id' => 100,
                        'callback_query' => [
                            'id' => 'callback-1',
                            'data' => 'ai_message_edit_555',
                            'from' => [
                                'id' => 10,
                                'is_bot' => false,
                                'first_name' => 'Operator',
                            ],
                            'message' => [
                                'message_id' => 555,
                                'message_thread_id' => 444,
                                'chat' => [
                                    'id' => -1003546470853,
                                    'type' => 'supergroup',
                                ],
                                'text' => 'AI draft',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'http://nginx/api/ai-bot/webhook' => Http::response(null, 204),
        ]);

        $this->artisan('ai-bot:poll-updates', [
            '--once' => true,
            '--timeout' => 1,
            '--sleep' => 1,
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://nginx/api/ai-bot/webhook'
                && $request->hasHeader('X-Telegram-Bot-Api-Secret-Token', 'ai-secret')
                && $request['update_id'] === 100
                && $request['callback_query']['data'] === 'ai_message_edit_555';
        });
    }
}
