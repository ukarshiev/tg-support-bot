<?php

namespace Tests\Unit\Modules\External\Services;

use App\Models\BotUser;
use App\Models\ExternalUser;
use App\Models\Message;
use App\Modules\External\Actions\DeleteMessage;
use App\Modules\External\DTOs\ExternalListMessageDto;
use App\Modules\External\DTOs\ExternalMessageDto;
use App\Modules\External\Services\ExternalTrafficService;
use App\Modules\Telegram\Jobs\SendExternalTelegramMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\External\ExternalMessageDtoMock;
use Tests\TestCase;

class ExternalTrafficServiceTest extends TestCase
{
    use RefreshDatabase;

    private mixed $source;

    private mixed $external_id;

    private BotUser $botUser;

    public function setUp(): void
    {
        parent::setUp();

        BotUser::truncate();
        Message::truncate();
        Queue::fake();

        $this->source = 'live_chat';
        $this->external_id = time();

        $externalUser = ExternalUser::firstOrCreate([
            'external_id' => $this->external_id,
            'source' => $this->source,
        ]);

        $this->botUser = BotUser::firstOrCreate([
            'chat_id' => $externalUser->id,
            'platform' => $this->source,
        ]);
    }

    public function createMessage(): Message
    {
        // Сохраняем сообщения в БД
        $whereMessageParams = [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'incoming',
            'platform' => $this->source,
            'from_id' => rand(),
            'to_id' => rand(),
        ];
        $createdMessage = Message::where($whereMessageParams)->firstOrCreate($whereMessageParams);

        $createdMessage->externalMessage()->create([
            'text' => 'Тестовое сообщение',
            'file_id' => null,
        ]);

        return $createdMessage;
    }

    public function test_get_list_messages(): void
    {
        $this->createMessage();

        // получаем список сообщений
        $filterDto = ExternalListMessageDto::from([
            'external_id' => $this->external_id,
            'source' => $this->source,
        ]);

        $result = (new ExternalTrafficService())->list($filterDto);

        $this->assertIsArray($result['messages']);
        $this->assertNotEmpty($result['messages']);
    }

    public function test_get_list_messages_returns_a_temporary_signed_file_url(): void
    {
        $message = $this->createMessage();
        $message->externalMessage()->update(['file_id' => 'telegram-file-id']);

        $filterDto = ExternalListMessageDto::from([
            'external_id' => $this->external_id,
            'source' => $this->source,
        ]);

        $result = (new ExternalTrafficService())->list($filterDto);
        $url = (string) $result['messages'][0]['file_url'];

        $this->assertStringContainsString('/api/files/telegram-file-id?', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_get_list_messages_error_messages_not_found(): void
    {
        $filterDto = ExternalListMessageDto::from([
            'external_id' => 'not_exist',
            'source' => $this->source,
        ]);

        $result = (new ExternalTrafficService())->list($filterDto);

        $this->assertIsArray($result);
        $this->assertFalse($result['status']);
        $this->assertEquals('Чат не найден!', $result['error']);
    }

    public function test_show(): void
    {
        $this->createMessage();

        $message = Message::where([
            'platform' => $this->source,
            'message_type' => 'incoming',
        ])->orderBy('id', 'desc')->first();

        $scope = ExternalMessageDto::from([
            'source' => $this->source,
            'external_id' => $this->external_id,
            'message_id' => $message->from_id,
        ]);
        $result = (new ExternalTrafficService())->show($message->id, $scope);

        $this->assertNotNull($result);
        $this->assertEquals($result->platform, $this->source);
        $this->assertEquals($result->message_type, $message->message_type);
    }

    public function test_show_does_not_return_message_from_another_external_user(): void
    {
        $message = $this->createMessage();
        $foreignScope = ExternalMessageDto::from([
            'source' => $this->source,
            'external_id' => 'another-session',
            'message_id' => $message->from_id,
        ]);

        $result = (new ExternalTrafficService())->show($message->id, $foreignScope);

        $this->assertNull($result);
    }

    public function test_send_file(): void
    {
        // отправляем сообщение
        $dataMessage = [
            'source' => $this->source,
            'external_id' => $this->external_id,
            'text' => 'Тестовое сообщение',
            'uploaded_file' => UploadedFile::fake()->create('image.jpg', 100, 'image/jpeg'),
        ];

        $externalDto = ExternalMessageDto::from($dataMessage);

        (new ExternalTrafficService())->sendFile($externalDto);

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendExternalTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];

        $this->assertEquals($this->botUser->id, $job->botUserId);
        $this->assertEquals('sendDocument', $job->queryParams->methodQuery, );
        $this->assertEquals('private', $job->queryParams->typeSource, );
        $this->assertEquals($job->queryParams->message_thread_id, $this->botUser->topic_id);
    }

    public function test_destroy(): void
    {
        $message = $this->createMessage();

        $payload = [
            'source' => $this->source,
            'external_id' => $this->external_id,
            'message_id' => $message->from_id,
        ];

        $deleteDto = ExternalMessageDtoMock::getDto($payload);

        (new ExternalTrafficService())->destroy($deleteDto);

        app(DeleteMessage::class)->execute($deleteDto);

        $this->assertNull(Message::first());
    }
}
