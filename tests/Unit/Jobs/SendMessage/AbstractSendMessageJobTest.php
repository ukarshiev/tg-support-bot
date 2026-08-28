<?php

namespace Tests\Unit\Jobs\SendMessage;

use App\Jobs\SendMessage\AbstractSendMessageJob;
use App\Models\BotUser;
use App\Modules\Telegram\DTOs\TelegramAnswerDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendTelegramTopicMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AbstractSendMessageJobTest extends TestCase
{
    use RefreshDatabase;

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

    #[DataProvider('forbiddenDescriptions')]
    public function test_telegram_403_is_classified_by_normalized_description_substring(
        string $description,
        bool $recipientUnavailable,
    ): void {
        Queue::fake();

        $botUser = BotUser::create([
            'chat_id' => 100001,
            'platform' => 'telegram',
            'topic_id' => 123,
        ]);
        $job = new RetryProbeSendMessageJob();
        $job->botUserId = $botUser->id;
        $job->updateDto = null;

        $job->handleTelegramResponse(new TelegramAnswerDto(
            ok: false,
            response_code: 403,
            rawData: [
                'ok' => false,
                'error_code' => 403,
                'description' => $description,
            ],
        ));

        $botUser->refresh();
        $this->assertSame($recipientUnavailable, $botUser->is_unavailable, $description);
        $this->assertSame($recipientUnavailable ? $description : null, $botUser->unavailable_reason);

        if ($recipientUnavailable) {
            $this->assertNotNull($botUser->unavailable_at);
            Queue::assertPushed(SendTelegramTopicMessageJob::class, fn (SendTelegramTopicMessageJob $queued): bool =>
                $queued->botUserId === $botUser->id
                && $queued->text === __('messages.ban_bot'));
        } else {
            $this->assertNull($botUser->unavailable_at);
            Queue::assertNotPushed(SendTelegramTopicMessageJob::class);
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function forbiddenDescriptions(): array
    {
        return [
            'blocked with variable prefix and casing' => [
                'FORBIDDEN: The Bot Was BLOCKED BY THE USER after delivery',
                true,
            ],
            'deactivated with variable whitespace' => [
                "Forbidden:  USER\nIS   DEACTIVATED",
                true,
            ],
            'kicked from supergroup' => [
                'Forbidden: bot was kicked from the supergroup chat',
                false,
            ],
            'not a supergroup member' => [
                'Forbidden: bot is not a member of the supergroup chat',
                false,
            ],
            'chat not found' => [
                'Forbidden: chat not found',
                false,
            ],
            'unknown forbidden description' => [
                'Forbidden: a new Telegram condition appeared',
                false,
            ],
        ];
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
