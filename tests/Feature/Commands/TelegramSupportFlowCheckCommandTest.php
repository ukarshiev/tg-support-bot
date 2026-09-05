<?php

namespace Tests\Feature\Commands;

use App\Console\Commands\TelegramSupportFlowCheck;
use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Services\SupportLanguageService;
use App\Modules\Telegram\Support\TelegramClientDeliveryRetryPolicy;
use App\Modules\Translation\Services\SupportLanguageSettings;
use App\Modules\Translation\Support\TelegramMarkupSanitizer;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSupportFlowCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_canary_timeout_is_distinct_from_delivery_window_and_run_deadline_is_capped(): void
    {
        $command = app(TelegramSupportFlowCheck::class);
        $awaitMethod = new \ReflectionMethod($command, 'defaultAwaitTimeoutSeconds');
        $deadlineMethod = new \ReflectionMethod($command, 'defaultRunDeadlineSeconds');

        $awaitTimeout = $awaitMethod->invoke($command);

        $this->assertSame(
            TelegramClientDeliveryRetryPolicy::canaryConfirmationTimeoutSeconds(),
            $awaitTimeout,
        );
        $this->assertSame(150, $awaitTimeout);
        $this->assertNotSame(TelegramClientDeliveryRetryPolicy::retryWindowSeconds(), $awaitTimeout);
        $this->assertLessThanOrEqual(
            TelegramClientDeliveryRetryPolicy::CANARY_RUN_DEADLINE_LIMIT_SECONDS,
            $deadlineMethod->invoke($command, 24, $awaitTimeout, 1100),
        );
        $this->assertSame(3600, $deadlineMethod->invoke($command, 24, $awaitTimeout, 1100));
    }

    public function test_unconfirmed_step_is_reported_as_timeout_not_final_failure(): void
    {
        $command = app(TelegramSupportFlowCheck::class);
        $awaitMethod = new \ReflectionMethod($command, 'awaitCheck');
        $reportMethod = new \ReflectionMethod($command, 'reportMessages');

        $check = $awaitMethod->invoke(
            $command,
            fn (): array => [
                'ok' => false,
                'step' => 'select pl',
                'detail' => 'исходная причина',
            ],
            0,
            microtime(true) + 1,
        );
        $report = implode("\n", $reportMethod->invoke(
            $command,
            [
                $check,
                [
                    'ok' => false,
                    'status' => 'failed',
                    'step' => 'select en',
                    'detail' => 'доставка окончательно провалилась',
                ],
            ],
            Carbon::parse('2026-09-05 01:00:00'),
            false,
        ));

        $this->assertSame('timed_out', $check['status']);
        $this->assertStringContainsString('не подтверждено за отведённое канарейке время', $check['detail']);
        $this->assertStringContainsString('не подтверждено вовремя 1', $report);
        $this->assertStringContainsString('окончательных ошибок 1', $report);
        $this->assertStringContainsString('⚠️ select pl', $report);
        $this->assertStringNotContainsString('❌ select pl', $report);
        $this->assertStringContainsString('❌ select en — доставка окончательно провалилась', $report);
    }

    public function test_support_flow_check_runs_start_lang_and_language_welcome_checks(): void
    {
        $this->configureHealthCheck(['pl'], ['pl']);
        $this->fakeTelegram();

        $this->artisan('telegram:support-flow-check', [
            '--await-timeout' => 1,
            '--language-pause' => 0,
            '--deadline' => 10,
        ])->assertSuccessful();

        $botUser = BotUser::where('chat_id', 555001)->firstOrFail();
        $this->assertSame('ru', $botUser->preferred_language_code);
        $this->assertSame('Dedicated Canary', $botUser->display_name);
        $this->assertSame('dedicated_canary', $botUser->username);

        $this->assertTrue(Message::query()
            ->where('bot_user_id', $botUser->id)
            ->where('message_type', 'outgoing')
            ->where('to_id', '>', 0)
            ->pluck('text')
            ->contains(fn ($text): bool => app(SupportLanguageService::class)
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

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/editMessageReplyMarkup'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '-1001'
            && $request['message_thread_id'] === 777
            && str_contains((string) $request['text'], 'Служебная проверка Telegram-flow'));
    }

    public function test_all_enabled_languages_are_checked_when_health_check_language_setting_is_empty(): void
    {
        $this->configureHealthCheck(['pl', 'en']);
        $this->fakeTelegram();

        $this->artisan('telegram:support-flow-check', [
            '--await-timeout' => 1,
            '--language-pause' => 0,
            '--deadline' => 10,
        ])
            ->expectsOutputToContain('OK select pl')
            ->expectsOutputToContain('OK select en')
            ->assertSuccessful();
    }

    public function test_explicit_health_check_language_setting_overrides_enabled_languages(): void
    {
        $this->configureHealthCheck(['pl', 'en'], ['pl']);
        $this->fakeTelegram();

        $this->artisan('telegram:support-flow-check', [
            '--await-timeout' => 1,
            '--language-pause' => 0,
            '--deadline' => 10,
        ])
            ->expectsOutputToContain('OK select pl')
            ->doesntExpectOutputToContain('select en')
            ->assertSuccessful();
    }

    public function test_second_run_exits_successfully_while_lock_is_held(): void
    {
        $this->configureHealthCheck(['pl']);
        Http::fake();
        $lock = Cache::lock('telegram:support-flow-check:lock', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('telegram:support-flow-check')
                ->expectsOutputToContain('another run is still active')
                ->assertSuccessful();

            $this->assertDatabaseCount('messages', 0);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_report_delegates_length_limiting_to_central_telegram_sender(): void
    {
        $checks = [];
        for ($index = 0; $index < 150; $index++) {
            $checks[] = [
                'ok' => false,
                'status' => 'failed',
                'step' => "select language-{$index}",
                'detail' => str_repeat('подробная ошибка ', 40),
                'language_code' => "language-{$index}",
            ];
        }

        $method = new \ReflectionMethod(TelegramSupportFlowCheck::class, 'reportMessages');
        $messages = $method->invoke(
            app(TelegramSupportFlowCheck::class),
            $checks,
            Carbon::parse('2026-08-29 01:00:00'),
            false,
        );

        $this->assertCount(1, $messages);
        $this->assertGreaterThan(4096, mb_strlen($messages[0]));
    }

    public function test_languages_not_started_before_deadline_are_reported_as_unchecked(): void
    {
        $this->configureHealthCheck(['pl', 'en']);
        $this->fakeTelegram();

        $this->artisan('telegram:support-flow-check', [
            '--await-timeout' => 2,
            '--language-pause' => 0,
            '--deadline' => 1,
        ])
            ->expectsOutputToContain('SKIP select pl')
            ->expectsOutputToContain('SKIP select en')
            ->assertFailed();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Не проверены из-за общего дедлайна: en, pl'));
    }

    /**
     * @param list<string>      $enabledCodes
     * @param list<string>|null $healthCheckCodes
     */
    private function configureHealthCheck(array $enabledCodes, ?array $healthCheckCodes = null): void
    {
        $settings = app(SettingsService::class);
        $settings->set('telegram.health_check_enabled', true);
        $settings->set('telegram.health_check_chat_id', '555001');
        $settings->set('telegram.token', 'test-token');
        $settings->set('telegram.group_id', '-1001');
        if ($healthCheckCodes !== null) {
            $settings->set('telegram.health_check_languages', $healthCheckCodes);
        }

        $configuredLanguages = [];
        foreach ($enabledCodes as $index => $code) {
            $language = config("support_languages.languages.{$code}");
            $configuredLanguages[$code] = [
                'code' => $code,
                'name' => $language['name'],
                'native' => $language['native'],
                'enabled' => true,
                'show_on_start' => true,
                'sort_order' => $index + 1,
            ];
        }
        app(SupportLanguageSettings::class)->save($configuredLanguages);

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
    }

    private function fakeTelegram(): void
    {
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
    }
}
