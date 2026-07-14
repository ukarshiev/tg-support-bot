<?php

namespace Tests\Feature\Commands;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Translation\Support\TelegramMarkupSanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSupportFlowCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_flow_check_runs_start_lang_and_language_welcome_checks(): void
    {
        app(SettingsService::class)->set('telegram.health_check_enabled', true);
        app(SettingsService::class)->set('telegram.health_check_chat_id', '555001');
        app(SettingsService::class)->set('telegram.health_check_languages', ['pl']);
        app(SettingsService::class)->set('telegram.token', 'test-token');
        app(SettingsService::class)->set('telegram.group_id', '-1001');

        BotUser::create([
            'chat_id' => 555001,
            'platform' => 'telegram',
            'display_name' => 'Dedicated Canary',
            'username' => 'dedicated_canary',
            'topic_id' => 777,
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
            'preferred_language_selected_at' => now()->subDay(),
        ]);

        $messageId = 9000;
        Http::fake(function (Request $request) use (&$messageId) {
            if (str_contains($request->url(), '/getChat')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => 555001,
                        'first_name' => 'Support',
                        'username' => 'support_flow_check',
                    ],
                ], 200);
            }

            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => ++$messageId,
                    'message_thread_id' => $request['message_thread_id'] ?? null,
                    'chat' => [
                        'id' => $request['chat_id'] ?? 555001,
                    ],
                    'text' => $request['text'] ?? '',
                ],
            ], 200);
        });

        $this->artisan('telegram:support-flow-check', [])
            ->assertSuccessful();

        $botUser = BotUser::where('chat_id', 555001)->firstOrFail();
        $this->assertSame('ru', $botUser->preferred_language_code);
        $this->assertSame('Dedicated Canary', $botUser->display_name);
        $this->assertSame('dedicated_canary', $botUser->username);

        $this->assertTrue(Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('message_type', 'outgoing')
            ->where('to_id', '>', 0)
            ->pluck('text')
            ->contains(fn ($text): bool => app(\App\Modules\Telegram\Services\SupportLanguageService::class)
                ->isSelectorText(is_string($text) ? $text : null)));

        $greeting = app(TelegramMarkupSanitizer::class)->toPlainText(
            app(SupportLanguageService::class)->greeting('pl'),
        );
        $this->assertTrue(Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('message_type', 'outgoing')
            ->where('text', $greeting)
            ->where('to_id', '>', 0)
            ->exists());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '-1001'
            && $request['message_thread_id'] === 777
            && str_contains((string) $request['text'], 'Служебная проверка Telegram-flow'));
    }
}
