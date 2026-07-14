<?php

namespace Tests\Feature\Jobs;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\DTOs\TGTextMessageDto;
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

    public function test_incoming_start_is_saved_once_and_support_delivery_is_decoupled(): void
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
        $job->handle();

        $this->assertSame(1, Message::query()
            ->where('bot_user_id', $this->botUser->id)
            ->where('message_type', 'incoming')
            ->where('from_id', 9001)
            ->where('text', '/start')
            ->count());

        Queue::assertPushed(SendTelegramMirrorJob::class, 2);
    }
}
