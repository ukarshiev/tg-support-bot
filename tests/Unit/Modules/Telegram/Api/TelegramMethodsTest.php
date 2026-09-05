<?php

namespace Tests\Unit\Modules\Telegram\Api;

use App\Modules\Telegram\Api\TelegramMethods;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramMethodsTest extends TestCase
{
    private int $chatId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chatId = time();
    }

    protected function getMessageParams(): array
    {
        return [
            'chat_id' => $this->chatId,
        ];
    }

    public function test_send_text_message(): void
    {
        $testMessage = 'Тестовое сообщение';

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => time(),
                    'from' => [
                        'id' => time(),
                        'is_bot' => true,
                        'first_name' => 'Prog-Time |Администратор сайта',
                        'username' => 'prog_time_bot',
                    ],
                    'chat' => [
                        'id' => time(),
                        'first_name' => 'Test',
                        'last_name' => 'test_file_id',
                        'username' => 'usertest',
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => $testMessage,
                ],
            ]),
        ]);

        $queryParams = array_merge($this->getMessageParams(), [
            'text' => $testMessage,
        ]);

        $resultQuery = TelegramMethods::sendQueryTelegram('sendMessage', $queryParams);

        $this->assertTrue($resultQuery->ok);

        $this->assertNotEmpty($resultQuery->response_code);
        $this->assertEquals($testMessage, $resultQuery->text);
    }

    public function test_send_document_and_caption(): void
    {
        $testMessage = 'Тестовое сообщение';

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => time(),
                    'from' => [
                        'id' => time(),
                        'is_bot' => true,
                        'first_name' => 'Prog-Time |Администратор сайта',
                        'username' => 'prog_time_bot',
                    ],
                    'chat' => [
                        'id' => time(),
                        'first_name' => 'Test',
                        'last_name' => 'test_file_id',
                        'username' => 'usertest',
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'document' => [
                        'file_name' => '119f98712538b4d27f0290c798d1f011.png',
                        'mime_type' => 'image/png',
                        'thumbnail' => [
                            'file_id' => 'test_file_id',
                            'file_unique_id' => 'AQADVoQAAi678Uly',
                            'file_size' => 13279,
                            'width' => 320,
                            'height' => 210,
                        ],
                        'thumb' => [
                            'file_id' => 'test_file_id',
                            'file_unique_id' => 'AQADVoQAAi678Uly',
                            'file_size' => 13279,
                            'width' => 320,
                            'height' => 210,
                        ],
                        'file_id' => 'test_file_id',
                        'file_unique_id' => 'AgADVoQAAi678Uk',
                        'file_size' => 1052715,
                    ],
                    'caption' => $testMessage,
                ],
            ]),
        ]);

        $queryParams = array_merge($this->getMessageParams(), [
            'caption' => $testMessage,
            'document' => 'BQACAgIAAxkBAAIHOmi-0ihwIBW1gZH2kie-2qZ39FKUAAJWhAACLrvxSdnwd0Zd4TtpNgQ',
        ]);

        $resultQuery = TelegramMethods::sendQueryTelegram('sendDocument', $queryParams);

        $this->assertTrue($resultQuery->ok);

        $this->assertEquals($resultQuery->response_code, 200);
        $this->assertEquals($testMessage, $resultQuery->text);
    }

    public function test_send_photo_and_caption(): void
    {
        $testMessage = 'Тестовое сообщение';

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => time(),
                    'from' => [
                        'id' => time(),
                        'is_bot' => true,
                        'first_name' => 'Prog-Time |Администратор сайта',
                        'username' => 'prog_time_bot',
                    ],
                    'chat' => [
                        'id' => time(),
                        'first_name' => 'Тестовый',
                        'last_name' => 'test_file_id',
                        'username' => 'usertest',
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'photo' => [
                        [
                            'file_id' => time(),
                            'file_unique_id' => 'AQADcPoxGy67-Ul4',
                            'file_size' => 899,
                            'width' => 90,
                            'height' => 58,
                        ],
                        [
                            'file_id' => 'test_file_id',
                            'file_unique_id' => 'AQADcPoxGy67-Uly',
                            'file_size' => 12933,
                            'width' => 320,
                            'height' => 208,
                        ],
                        [
                            'file_id' => 'test_file_id',
                            'file_unique_id' => 'AQADcPoxGy67-Ul9',
                            'file_size' => 56681,
                            'width' => 800,
                            'height' => 521,
                        ],
                        [
                            'file_id' => 'test_file_id',
                            'file_unique_id' => 'AQADcPoxGy67-Ul-',
                            'file_size' => 83643,
                            'width' => 1280,
                            'height' => 833,
                        ],
                    ],
                    'caption' => $testMessage,
                ],
            ]),
        ]);

        $queryParams = array_merge($this->getMessageParams(), [
            'caption' => $testMessage,
            'photo' => 'AgACAgIAAxkBAAIHO2i-0nqM0rxqaqBPjrcf9937EzNRAAJw-jEbLrv5SSpf9j0qc59iAQADAgADeQADNgQ',
        ]);

        $resultQuery = TelegramMethods::sendQueryTelegram('sendPhoto', $queryParams);

        $this->assertTrue($resultQuery->ok);

        $this->assertEquals($resultQuery->response_code, 200);
        $this->assertEquals($testMessage, $resultQuery->text);
    }

    public function test_central_sender_splits_long_text_and_returns_last_message(): void
    {
        $messageId = 100;
        Http::fake(function ($request) use (&$messageId) {
            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => $messageId++,
                    'chat' => ['id' => $this->chatId, 'type' => 'private'],
                    'date' => time(),
                    'text' => $request->data()['text'],
                ],
            ]);
        });
        $text = str_repeat('длинный текст со словами ', 300);

        $response = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'html',
            'reply_markup' => ['inline_keyboard' => [[['text' => 'OK', 'callback_data' => 'ok']]]],
        ]);

        $requests = collect(Http::recorded())->map(fn (array $record) => $record[0])->values();
        $this->assertGreaterThan(1, $requests->count());
        $this->assertSame($text, $requests->map(fn ($request) => $request->data()['text'])->implode(''));
        $this->assertArrayNotHasKey('reply_markup', $requests->first()->data());
        $this->assertArrayHasKey('reply_markup', $requests->last()->data());
        $this->assertSame($messageId - 1, $response->message_id);
    }

    public function test_retry_of_multipart_message_skips_parts_already_delivered(): void
    {
        $idempotencyKey = 'telegram-methods-test:' . uniqid('', true);
        Cache::forget('telegram:request-sequence:' . hash('sha256', $idempotencyKey));
        $text = str_repeat('А', 4000) . ' '
            . str_repeat('Б', 4000) . ' '
            . str_repeat('В', 4000);
        $success = static fn (int $messageId): array => [
            'ok' => true,
            'result' => [
                'message_id' => $messageId,
                'chat' => ['id' => 123, 'type' => 'private'],
                'date' => time(),
            ],
        ];

        Http::fakeSequence()
            ->push($success(101))
            ->push($success(102))
            ->push(['ok' => false, 'error_code' => 500, 'description' => 'Temporary failure'], 500)
            ->push($success(103));

        $firstAttempt = TelegramMethods::sendQueryTelegram(
            'sendMessage',
            ['chat_id' => 123, 'text' => $text],
            'test-token',
            $idempotencyKey,
        );
        $secondAttempt = TelegramMethods::sendQueryTelegram(
            'sendMessage',
            ['chat_id' => 123, 'text' => $text],
            'test-token',
            $idempotencyKey,
        );

        $sentParts = collect(Http::recorded())->map(fn (array $record): string => (string) $record[0]->data()['text']);
        $this->assertFalse($firstAttempt->ok);
        $this->assertTrue($secondAttempt->ok);
        $this->assertSame(103, $secondAttempt->message_id);
        $this->assertCount(4, $sentParts);
        $this->assertSame(1, $sentParts->filter(fn (string $part): bool => $part === $sentParts[0])->count());
        $this->assertSame(1, $sentParts->filter(fn (string $part): bool => $part === $sentParts[1])->count());
        $this->assertSame($sentParts[2], $sentParts[3]);
    }

    public function test_central_sender_truncates_caption_and_sends_full_text_after_media(): void
    {
        $messageId = 200;
        Http::fake(function ($request) use (&$messageId) {
            $data = $request->data();

            return Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => $messageId++,
                    'chat' => ['id' => $this->chatId, 'type' => 'private'],
                    'date' => time(),
                    'text' => $data['text'] ?? null,
                    'caption' => $data['caption'] ?? null,
                ],
            ]);
        });
        $caption = str_repeat('подпись со словами ', 100);

        $response = TelegramMethods::sendQueryTelegram('sendVideo', [
            'chat_id' => $this->chatId,
            'video' => 'file-id',
            'caption' => $caption,
        ]);

        $requests = collect(Http::recorded())->map(fn (array $record) => $record[0])->values();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('подпись усечена', $requests[0]->data()['caption']);
        $this->assertLessThanOrEqual(1024, mb_strlen($requests[0]->data()['caption']));
        $this->assertSame($caption, $requests[1]->data()['text']);
        $this->assertStringContainsString('/sendVideo', $requests[0]->url());
        $this->assertStringContainsString('/sendMessage', $requests[1]->url());
        $this->assertSame(200, $response->message_id);
    }

    public function test_message_too_long_is_classified_and_logged_with_method_and_length(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message is too long',
            ], 400),
        ]);
        Log::shouldReceive('channel')->with('app')->once()->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Telegram rejected an oversized outgoing message; retry disabled'
                && $context['source'] === 'telegram_message_too_long'
                && $context['method'] === 'sendMessage'
                && $context['actual_length'] === 10,
        );

        $response = TelegramMethods::sendQueryTelegram('sendMessage', [
            'chat_id' => $this->chatId,
            'text' => '1234567890',
        ]);

        $this->assertFalse($response->ok);
        $this->assertSame('MESSAGE_TOO_LONG', $response->type_error);
    }
}
