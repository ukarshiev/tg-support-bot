<?php

namespace Tests\Unit\Modules\External\Services;

use App\Models\BotUser;
use App\Modules\External\DTOs\ExternalMessageDto;
use App\Modules\External\Services\ExternalMessageService;
use App\Modules\Telegram\Jobs\SendExternalTelegramMessageJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\External\ExternalMessageDtoMock;
use Tests\TestCase;

class ExternalMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public string $source;

    public int $external_id;

    public string $text;

    public BotUser $botUser;

    private ExternalMessageDto $dto;

    public function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->text = 'Тестовое сообщение';

        $this->dto = ExternalMessageDtoMock::getDto();

        $this->botUser = (new BotUser())->getOrCreateExternalBotUser($this->dto);
    }

    public function test_send_message(): void
    {
        // отправляем сообщение
        (new ExternalMessageService($this->dto))->handleUpdate();

        /** @phpstan-ignore-next-line */
        $pushed = Queue::pushedJobs()[SendExternalTelegramMessageJob::class] ?? [];
        $this->assertCount(1, $pushed);

        $job = $pushed[0]['job'];

        $this->assertEquals($this->botUser->id, $job->botUserId);
        $this->assertEquals('sendMessage', $job->queryParams->methodQuery, );
        $this->assertEquals('private', $job->queryParams->typeSource, );
        $this->assertEquals($job->queryParams->message_thread_id, $this->botUser->topic_id);
    }

    public function test_persists_incoming_directly_when_no_group(): void
    {
        // Always-both: without a Telegram group the message is saved directly
        // for the admin workspace and NOT forwarded to any group.
        $settings = app(\App\Services\Settings\SettingsService::class);
        $settings->forget('telegram.token');
        $settings->forget('telegram.secret_key');
        $settings->forget('telegram.group_id');

        (new ExternalMessageService($this->dto))->handleUpdate();

        $this->assertDatabaseHas('external_messages', ['text' => $this->dto->text]);
        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $this->botUser->id,
            'message_type' => 'incoming',
            'platform' => $this->botUser->platform,
        ]);
        Queue::assertNotPushed(SendExternalTelegramMessageJob::class);
    }

    public function test_external_bot_user_gets_readable_display_name(): void
    {
        $this->assertSame('Посетитель сайта', $this->botUser->display_name);
    }
}
