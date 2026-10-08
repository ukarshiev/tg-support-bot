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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Mocks\Tg\Answer\TelegramAnswerDtoMock;
use Tests\Mocks\Tg\TelegramUpdateDtoMock;
use Tests\TestCase;

class SendTelegramMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_serialized_retry_after_message_save_failure_does_not_send_twice(): void
    {
        config(['cache.default' => 'array']);
        $this->botUser->update(['topic_id' => null]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 901]])]);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage', 'chat_id' => $this->botUser->chat_id,
            'text' => 'Retry regression', 'token' => 'test-token',
        ]);
        $job = new SendTelegramMessageJob($this->botUser->id, $this->dto, $params, 'outgoing');
        $serialized = serialize($job);
        $failSave = true;
        Message::saving(static function () use (&$failSave): void {
            if ($failSave) {
                $failSave = false;
                throw new \RuntimeException('Simulated message save failure');
            }
        });
        try {
            $job->handle();
            $this->fail('The first save must fail after Telegram confirms delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated message save failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('delivery_operations', ['trace_id' => $job->traceId, 'status' => DeliveryOperation::STATUS_PROCESSING]);
        $retry = unserialize($serialized);
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $retry->setJob($queueJob);
        Log::spy();
        Log::shouldReceive('channel')->with('app')->andReturnSelf();
        $retry->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['bot_user_id' => $this->botUser->id, 'to_id' => 901]);
        $this->assertDatabaseHas('delivery_operations', ['trace_id' => $job->traceId, 'status' => DeliveryOperation::STATUS_DELIVERED]);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool =>
            ($context['source'] ?? null) === 'telegram_delivery_previous_attempt_uncertain'
            && $context['bot_user_id'] === $this->botUser->id
            && $context['trace_id'] === $job->traceId && $context['attempt'] === 2)->once();
    }

    public function test_already_delivered_retry_queues_mirror_without_client_request(): void
    {
        config(['cache.default' => 'array']);
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update(['topic_id' => null]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 902]])]);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage', 'chat_id' => $this->botUser->chat_id,
            'text' => 'Mirror recovery', 'token' => 'test-token',
        ]);
        $job = new SendTelegramMessageJob($this->botUser->id, $this->dto, $params, 'outgoing');
        $job->handle();
        Queue::fake();
        Http::fake();

        unserialize(serialize($job))->handle();

        Http::assertNothingSent();
        Queue::assertPushed(SendTelegramMirrorJob::class, 1);
        $this->assertDatabaseCount('messages', 1);
    }

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

    public function test_outgoing_video_saves_caption_and_video_attachment(): void
    {
        $caption = 'Подпись оператора';
        $dtoParams = TelegramAnswerDtoMock::getDtoParams();
        $dtoParams['result']['caption'] = $caption;
        unset($dtoParams['result']['text']);
        $response = TelegramAnswerDtoMock::getDto($dtoParams);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods
            ->shouldReceive('sendQueryTelegram')
            ->once()
            ->with('sendVideo', \Mockery::on(fn (array $params): bool =>
                ($params['video'] ?? null) === 'VIDEO_FROM_OPERATOR'
                && ($params['caption'] ?? null) === $caption
                && !array_key_exists('text', $params)), null, \Mockery::type('string'))
            ->andReturn($response);

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendVideo',
            'chat_id' => $this->botUser->chat_id,
            'video' => 'VIDEO_FROM_OPERATOR',
            'caption' => $caption,
        ]);

        (new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $mockTelegramMethods,
        ))->handle();

        $message = Message::where('bot_user_id', $this->botUser->id)
            ->where('message_type', 'outgoing')
            ->first();
        $this->assertNotNull($message);
        $this->assertSame($caption, $message->text);
        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'file_type' => 'video',
            'file_id' => 'VIDEO_FROM_OPERATOR',
        ]);
    }

    public function test_delivered_outgoing_video_is_not_sent_again_when_same_job_retries(): void
    {
        $this->botUser->update(['topic_id' => null]);
        $response = TelegramAnswerDtoMock::getDto();

        /** @var TelegramMethods&\Mockery\MockInterface $telegram */
        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')
            ->once()
            ->with('sendVideo', \Mockery::on(fn (array $params): bool =>
                ($params['video'] ?? null) === 'IDEMPOTENT_VIDEO'), null, \Mockery::type('string'))
            ->andReturn($response);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendVideo',
            'chat_id' => $this->botUser->chat_id,
            'video' => 'IDEMPOTENT_VIDEO',
            'caption' => 'Видео оператора',
        ]);
        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $telegram,
        );
        $traceId = $job->traceId;

        $job->handle();

        $this->assertDatabaseHas('delivery_operations', [
            'bot_user_id' => $this->botUser->id,
            'trace_id' => $traceId,
            'destination' => 'telegram-client',
            'operation' => 'sendVideo',
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'external_message_id' => $response->message_id,
        ]);

        $job->handle();

        $this->assertSame($traceId, $job->traceId);
        $this->assertSame(1, DeliveryOperation::where('trace_id', $traceId)
            ->where('operation', 'sendVideo')
            ->where('status', DeliveryOperation::STATUS_DELIVERED)
            ->count());
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

    public function test_confirmed_user_block_posts_only_dedicated_notice_without_raw_403_notification(): void
    {
        $blocked = new \App\Modules\Telegram\DTOs\TelegramAnswerDto(
            ok: false,
            response_code: 403,
            type_error: 'FORBIDDEN',
            rawData: [
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ],
        );
        $telegram = \Mockery::mock(TelegramMethods::class);
        $telegram->shouldReceive('sendQueryTelegram')->once()->andReturn($blocked);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => $this->botUser->chat_id,
            'text' => 'Ответ заблокировавшему пользователю',
        ]);
        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $this->dto,
            $params,
            'outgoing',
            $telegram,
        );

        $job->handle();

        $this->assertTrue($this->botUser->refresh()->is_unavailable);
        $this->assertDatabaseHas('delivery_operations', [
            'trace_id' => $job->traceId,
            'status' => DeliveryOperation::STATUS_FAILED,
        ]);
        Queue::assertNotPushed(NotifyAdminReplyDeliveryFailedJob::class);
        Queue::assertPushed(\App\Modules\Telegram\Jobs\SendTelegramTopicMessageJob::class, 1);
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

    public function test_incoming_service_commands_are_not_saved_or_mirrored(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update(['topic_id' => 777]);

        /** @var TelegramMethods&\Mockery\MockInterface $mockTelegramMethods */
        $mockTelegramMethods = \Mockery::mock(TelegramMethods::class);
        $mockTelegramMethods->shouldNotReceive('sendQueryTelegram');

        foreach (['/start', '/lang', '/language'] as $index => $command) {
            $dtoParams = TelegramUpdateDtoMock::getDtoParams();
            $dtoParams['message']['message_id'] = 9001 + $index;
            $dtoParams['message']['text'] = $command;
            $dto = TelegramUpdateDtoMock::getDto($dtoParams);

            $params = TGTextMessageDto::from([
                'methodQuery' => 'sendMessage',
                'chat_id' => '-100123456789',
                'message_thread_id' => 777,
                'text' => $command,
            ]);

            (new SendTelegramMessageJob(
                $this->botUser->id,
                $dto,
                $params,
                'incoming',
                $mockTelegramMethods,
            ))->handle();
        }

        $this->assertSame(0, Message::query()
            ->where('bot_user_id', $this->botUser->id)
            ->where('message_type', 'incoming')
            ->whereIn('text', ['/start', '/lang', '/language'])
            ->count());

        Queue::assertNotPushed(SendTelegramMirrorJob::class);
        Queue::assertNotPushed(TopicCreateJob::class);
    }

    public function test_first_real_message_without_selected_language_queues_contact_before_support_mirror(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update([
            'topic_id' => null,
            'preferred_language_code' => null,
            'preferred_language_name' => null,
            'preferred_language_selected_at' => null,
        ]);

        $dtoParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoParams['message']['message_id'] = 9002;
        $dtoParams['message']['text'] = 'Мне нужна помощь';
        $dto = TelegramUpdateDtoMock::getDto($dtoParams);

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => '-100123456789',
            'text' => 'Мне нужна помощь',
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

    public function test_first_real_message_with_selected_language_queues_contact_before_support_mirror(): void
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

    public function test_second_real_message_queues_only_support_mirror(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update(['topic_id' => 777]);
        Message::create([
            'bot_user_id' => $this->botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 9003,
            'to_id' => 10,
            'text' => 'Первое сообщение',
        ]);

        $dtoParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoParams['message']['message_id'] = 9004;
        $dtoParams['message']['text'] = 'Второе сообщение';
        $dto = TelegramUpdateDtoMock::getDto($dtoParams);

        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => '-100123456789',
            'message_thread_id' => 777,
            'text' => 'Второе сообщение',
        ]);

        (new SendTelegramMessageJob(
            $this->botUser->id,
            $dto,
            $params,
            'incoming',
            \Mockery::mock(TelegramMethods::class),
        ))->handle();

        Queue::assertNotPushed(TopicCreateJob::class);
        Queue::assertPushed(SendTelegramMirrorJob::class, 1);
    }

    public function test_repeated_incoming_update_uses_same_contact_operation_key(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('telegram.group_id', '-100123456789');
        $this->botUser->update([
            'topic_id' => null,
            'preferred_language_code' => null,
        ]);

        $dtoParams = TelegramUpdateDtoMock::getDtoParams();
        $dtoParams['message']['message_id'] = 9005;
        $dtoParams['message']['text'] = 'Повторяемое сообщение';
        $dto = TelegramUpdateDtoMock::getDto($dtoParams);
        $params = TGTextMessageDto::from([
            'methodQuery' => 'sendMessage',
            'chat_id' => '-100123456789',
            'text' => 'Повторяемое сообщение',
        ]);
        $job = new SendTelegramMessageJob(
            $this->botUser->id,
            $dto,
            $params,
            'incoming',
            \Mockery::mock(TelegramMethods::class),
        );

        $job->handle();
        $job->handle();

        $expectedOperationKey = hash('sha256', "telegram-first-contact|{$this->botUser->id}");
        $topicJobs = Queue::pushed(TopicCreateJob::class);
        $this->assertCount(2, $topicJobs);

        foreach ($topicJobs as $topicJob) {
            $contactJob = unserialize($topicJob->chained[0]);
            $this->assertInstanceOf(SendContactMessageJob::class, $contactJob);
            $this->assertSame($expectedOperationKey, $contactJob->operationKey);
        }
    }
}
