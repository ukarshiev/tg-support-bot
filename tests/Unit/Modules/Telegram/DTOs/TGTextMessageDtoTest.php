<?php

namespace Tests\Unit\Modules\Telegram\DTOs;

use App\Modules\Telegram\DTOs\TGTextMessageDto;
use Tests\TestCase;

class TGTextMessageDtoTest extends TestCase
{
    /**
     * Regression: telegram.group_id is stored/cast as a string in settings and
     * passed as chat_id (e.g. ExternalMessageService). The DTO must accept it
     * without a TypeError — Telegram's chat_id is int|string.
     */
    public function test_accepts_string_chat_id_from_group_id(): void
    {
        $dto = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'typeSource' => 'private',
            'chat_id' => '-1001234567890',
            'message_thread_id' => 12,
            'text' => 'hi',
        ]);

        $this->assertSame('-1001234567890', $dto->chat_id);
        $this->assertSame('-1001234567890', $dto->toArray()['chat_id']);
    }

    public function test_accepts_int_chat_id(): void
    {
        $dto = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => 123456789,
            'text' => 'hi',
        ]);

        $this->assertSame(123456789, $dto->chat_id);
    }

    public function test_answer_callback_query_payload_does_not_include_chat_fields(): void
    {
        $dto = TGTextMessageDto::from([
            'methodQuery' => 'answerCallbackQuery',
            'chat_id' => 0,
            'message_thread_id' => 12,
            'callback_query_id' => '777',
            'text' => 'Подсказка',
            'parse_mode' => 'html',
        ]);

        $payload = $dto->toArray();

        $this->assertSame('777', $payload['callback_query_id']);
        $this->assertSame('Подсказка', $payload['text']);
        $this->assertArrayNotHasKey('chat_id', $payload);
        $this->assertArrayNotHasKey('message_thread_id', $payload);
        $this->assertArrayNotHasKey('parse_mode', $payload);
    }
}
