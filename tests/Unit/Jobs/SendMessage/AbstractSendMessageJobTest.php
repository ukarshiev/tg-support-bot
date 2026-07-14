<?php

namespace Tests\Unit\Jobs\SendMessage;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use Tests\TestCase;

class AbstractSendMessageJobTest extends TestCase
{
    public function test_transient_telegram_error_releases_job_for_retry(): void
    {
        $job = (new RetryProbeSendMessageJob())->withFakeQueueInteractions();
        $job->botUserId = 777;
        $job->typeMessage = 'outgoing';

        $job->handleTelegramResponse(new TelegramAnswerDto(
            ok: false,
            response_code: 500,
            type_error: null,
            rawData: [
                'ok' => false,
                'response_code' => 500,
                'result' => 'cURL error 28: Operation timed out',
            ],
        ));

        $job->assertReleased(2);
        $job->assertNotFailed();
    }

    public function test_parse_entities_error_switches_message_to_plain_text_and_retries(): void
    {
        $job = (new RetryProbeSendMessageJob())->withFakeQueueInteractions();
        $job->botUserId = 777;
        $job->typeMessage = 'outgoing';
        $job->queryParams = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => 123,
            'text' => 'Link: <x id= "tgph0" > https://t.me/test< / x>',
            'parse_mode' => 'html',
        ]);

        $job->handleTelegramResponse(new TelegramAnswerDto(
            ok: false,
            response_code: 400,
            type_error: 'MARKDOWN_ERROR',
            rawData: [
                'ok' => false,
                'error_code' => 400,
                'description' => "Bad Request: can't parse entities",
            ],
        ));

        $job->assertReleased(1);
        $job->assertNotFailed();
        $this->assertNull($job->queryParams->parse_mode);
        $this->assertSame('Link:  https://t.me/test', $job->queryParams->text);
    }
}

class RetryProbeSendMessageJob extends AbstractSendMessageJob
{
    public function handle(): void
    {
        // Test probe only.
    }

    public function handleTelegramResponse(TelegramAnswerDto $response): void
    {
        $this->telegramResponseHandler($response);
    }

    protected function saveMessage(BotUser $botUser, mixed $resultQuery): void
    {
        // Test probe only.
    }

    protected function editMessage(BotUser $botUser, mixed $resultQuery): void
    {
        // Test probe only.
    }
}
