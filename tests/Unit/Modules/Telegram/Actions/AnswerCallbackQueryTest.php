<?php

namespace Tests\Unit\Modules\Telegram\Actions;

use App\Modules\Telegram\Actions\AnswerCallbackQuery;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnswerCallbackQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_answers_callback_silently_and_quickly(): void
    {
        Http::fake([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(AnswerCallbackQuery::class)->execute($this->callbackUpdate('callback-1'));

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/answerCallbackQuery')
                && $request['callback_query_id'] === 'callback-1'
                && $request['cache_time'] === 0
                && !isset($request['text']);
        });
    }

    public function test_callback_answer_failure_does_not_throw(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('telegram network is unavailable');
        });

        app(AnswerCallbackQuery::class)->execute($this->callbackUpdate('callback-2'));

        $this->assertTrue(true);
    }

    public function test_empty_token_does_not_send_request(): void
    {
        app(SettingsService::class)->set('telegram.token', '');

        Http::fake([
            '*' => Http::response(['ok' => true]),
        ]);

        app(AnswerCallbackQuery::class)->execute($this->callbackUpdate('callback-3'));

        Http::assertNothingSent();
    }

    private function callbackUpdate(string $callbackId): TelegramUpdateDto
    {
        return TelegramUpdateDto::fromRequest(request()->create('/api/telegram/bot', 'POST', [
            'update_id' => 1,
            'callback_query' => [
                'id' => $callbackId,
                'from' => [
                    'id' => 123456,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'language_code' => 'pl',
                ],
                'message' => [
                    'message_id' => 10,
                    'chat' => [
                        'id' => 123456,
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => "Выберите язык / Choose your language:\nСтраница 2/2",
                ],
                'data' => 'select_language:pl',
            ],
        ]));
    }
}
