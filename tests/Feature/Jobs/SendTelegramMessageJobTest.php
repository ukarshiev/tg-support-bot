<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Admin\Jobs\NotifyAdminReplyDeliveryFailedJob;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
use App\Modules\Telegram\Jobs\SendContactMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Jobs\SendTelegramMirrorJob;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Tg\Answer\TelegramAnswerDtoMock;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendTelegramMessageJobTest extends TestCase
{
    use RefreshDatabase;

    private TelegramUpdateDto $dto;

    private ?BotUser $botUser;

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Message::truncate();

        $this->dto = TelegramUpdateDtoMock::getDto();
        $this->botUser = BotUser::getOrCreateByTelegramUpdate($this->dto);
        $this->botUser->update(['topic_id' => 777]);
    }

    public function test_success_send_creates_message_record(): void
    {
        $typeMessage = 'outgoing';

        $textMessage = 'hello';
        $dtoParams = TelegramAnswerDtoMock::getDtoParams();

        $dtoParams['result']['text'] = $textMessage;
        $dto = TelegramAnswerDtoMock::getDto($dtoParams);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods->shouldReceive('sendQueryTelegram')->andReturn($dto);

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => $textMessage,
        ]);

        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            $typeMessage,
            $mockTelegramMethods
        );
        $job->handle();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => $typeMessage,
            'platform' => 'telegram',
            'to_id' => $dto->message_id,
        ]);
    }

    public function test_transient_failure_stays_retrying_and_later_delivery_succeeds(): void
    {
        $failed = new \App\Modules\Telegram\DTOs\TelegramAnswerDto(
            ok: false,
            response_code: 500,
            rawData: ['ok' => false, 'response_code' => 500],
        );
        $delivered = TelegramAnswerDtoMock::getDto();
        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->twice()->andReturn($failed, $delivered);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Ответ после восстановления связи',
        ]);
        $job = (new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $telegram,
        ))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(5);
        $this->assertDatabaseHas('delivery_operations', [
            'trace_id' => $job->traceId,
            'status' => DeliveryOperation::STATUS_RETRYING,
        ]);
        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);

        $job->handle();

        $this->assertDatabaseHas('delivery_operations', [
            'trace_id' => $job->traceId,
            'status' => DeliveryOperation::STATUS_DELIVERED,
        ]);
        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);
    }

    public function test_exhausted_retry_window_marks_failure_and_queues_notification(): void
    {
        $failed = new \App\Modules\Telegram\DTOs\TelegramAnswerDto(
            ok: false,
            response_code: 500,
            rawData: ['ok' => false, 'response_code' => 500],
        );
        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->once()->andReturn($failed);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Недоставленный ответ',
        ]);
        $job = (new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $telegram,
        ))->withFakeQueueInteractions();
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->andReturn($job->tries);
        $job->setJob($queueJob);

        try {
            $job->handle();
            $this->fail('The last transient failure must exhaust the retry window.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('retry window was exhausted', $exception->getMessage());
        }

        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);
        $job->failed(new \RuntimeException('retry window exhausted'));

        $this->assertDatabaseHas('delivery_operations', [
            'trace_id' => $job->traceId,
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
        Queue::assertPushed(NotifyAdminReplyDeliveryFailedJob::class, 1);
    }

    public function test_outgoing_bot_message_is_mirrored_to_support_topic(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');

        $textMessage = 'FULL WELCOME visible in support topic';
        $dtoParams = TelegramAnswerDtoMock::getDtoParams();
        $dtoParams['result']['text'] = $textMessage;
        $dto = TelegramAnswerDtoMock::getDto($dtoParams);

        $calls = [];

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods
            ->shouldReceive('sendQueryTelegram')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function (...$args) use (&$calls, $dto) {
                $calls[] = $args;

                return $dto;
            });

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => $textMessage,
        ]);

        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $mockTelegramMethods
        );
        $job->handle();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'outgoing',
            'platform' => 'telegram',
            'text' => $textMessage,
        ]);

        $this->assertTrue(collect($calls)->contains(
            fn (array $call): bool => $call[0] === 'sendMessage'
                && (int) ($call[1]['chat_id'] ?? 0) === $this->botUser->chat_id
                && ($call[1]['text'] ?? null) === $textMessage
        ));

        Queue::assertPushed(SendTelegramMirrorJob::class, function (SendTelegramMirrorJob $job) use ($textMessage): bool {
            return $job->botUserId === $this->botUser->id
                && str_contains($job->text, '🤖 Бот клиенту:')
                && str_contains($job->text, $textMessage)
                && $job->queue === 'telegram-mirror';
        });
    }

    public function test_operator_message_from_supergroup_is_not_echoed_back_to_support_topic(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');

        $raw = TelegramUpdateDtoMock::getDtoParams();
        $raw['message']['chat'] = [
            'id' => -100123456789,
            'type' => 'supergroup',
        ];
        $raw['message']['message_thread_id'] = 777;
        $raw['message']['text'] = 'Если хотите задать вопрос, пишите';
        $operatorUpdate = TelegramUpdateDtoMock::getDto($raw);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods->shouldReceive('sendQueryTelegram')->once()->andReturn(TelegramAnswerDtoMock::getDto());

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Если хотите задать вопрос, пишите',
        ]);

        (new SendTelegramMessageJob(
            $this->botUser->id,
            $operatorUpdate,
            $params,
            'outgoing',
            $mockTelegramMethods,
        ))->handle();

        Queue::assertNotPushed(SendTelegramMirrorJob::class);
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Если хотите задать вопрос, пишите',
        ]);
    }

    public function test_language_selector_reaches_client_without_being_mirrored_to_support_topic(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update(['topic_id' => null]);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods->shouldReceive('sendQueryTelegram')->once()->andReturn(TelegramAnswerDtoMock::getDto());

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Выберите язык / Choose your language:',
        ]);

        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $mockTelegramMethods
        );
        $job->handle();

        Queue::assertNotPushed(TopicCreateJob::class);
        Queue::assertNotPushed(SendTelegramMirrorJob::class);
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Выберите язык / Choose your language:',
        ]);
    }

    public function test_incoming_start_is_not_saved_or_mirrored(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update(['topic_id' => 777]);

        $dtoParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoParams['message']['message_id'] = 9001;
        $dtoParams['message']['text'] = '/start';
        $dto = TelegramUpdateDtoMock::getDto($dtoParams);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods->shouldNotReceive('sendQueryTelegram');

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => '-100123456789',
            'message_thread_id' => 777,
            'text' => '/start',
        ]);

        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $dto,
            $params,
            'incoming',
            $mockTelegramMethods
        );

        $job->handle();

        $this->assertSame(0, Message::query()
            ->where('bot_user_id', $this->botUser->id)
            ->where('message_type', 'incoming')
            ->where('from_id', 9001)
            ->where('text', '/start')
            ->count());

        Queue::assertNotPushed(SendTelegramMirrorJob::class);
        Queue::assertNotPushed(TopicCreateJob::class);
    }

    public function test_first_real_message_queues_contact_before_support_mirror(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update([
            'topic_id' => null,
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
            'preferred_language_selected_at' => now(),
        ]);

        $dtoParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoParams['message']['message_id'] = 9002;
        $dtoParams['message']['text'] = 'I need help';
        $dto = TelegramUpdateDtoMock::getDto($dtoParams);

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => '-100123456789',
            'text' => 'I need help',
        ]);

        (new SendTelegramMessageJob(
            $this->botUser->id,
            $dto,
            $params,
            'incoming',
            \Mockery::mock(TelegramMethods::class),
        ))->handle();

        Queue::assertPushedWithChain(TopicCreateJob::class, [
            SendContactMessageJob::class,
            SendTelegramMirrorJob::class,
        ]);
        Queue::assertNotPushed(SendTelegramMirrorJob::class);
    }
}
