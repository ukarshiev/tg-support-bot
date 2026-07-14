<?php

namespace Tests\Unit\Modules\Vk\Services;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Vk\Jobs\MirrorVkIncomingMessageJob;
use App\Modules\Vk\Services\VkMessageService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Vk\VkUpdateDtoMock;
use Tests\TestCase;

class VkMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->payload = VkUpdateDtoMock::getDtoParams();
        $this->botUser = BotUser::getUserByChatId(
            $this->payload['object']['message']['from_id'],
            'vk',
        );
    }

    public function test_message_is_saved_before_telegram_mirror_is_queued(): void
    {
        $dto = VkUpdateDtoMock::getDto($this->payload);

        (new VkMessageService($dto))->handleUpdate();

        $message = Message::where('bot_user_id', $this->botUser->id)->sole();
        $this->assertSame($dto->id, (int) $message->from_id);
        $this->assertSame($dto->text, $message->text);
        Queue::assertPushed(MirrorVkIncomingMessageJob::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_repeated_delivery_does_not_duplicate_message_or_attachments(): void
    {
        $this->payload['object']['message']['attachments'] = [[
            'type' => 'doc',
            'doc' => [
                'title' => 'test.pdf',
                'url' => 'https://example.com/test.pdf',
            ],
        ]];
        $dto = VkUpdateDtoMock::getDto($this->payload);

        (new VkMessageService($dto))->handleUpdate();
        (new VkMessageService($dto))->handleUpdate();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_attachments', 1);
    }

    public function test_ai_is_blocked_until_language_is_selected(): void
    {
        app(SettingsService::class)->set('ai.enabled', true);
        $dto = VkUpdateDtoMock::getDto($this->payload);

        (new VkMessageService($dto))->handleUpdate();

        Queue::assertNotPushed(SendAiDraftJob::class);
        Queue::assertNotPushed(SendAiReplyJob::class);
    }

    public function test_media_caption_is_forwarded_to_ai_after_language_selection(): void
    {
        app(SettingsService::class)->set('ai.enabled', true);
        app(SettingsService::class)->set('ai.auto_reply', false);
        $this->botUser->update(['preferred_language_code' => 'en']);
        $this->payload['object']['message']['text'] = 'Please inspect this file';
        $this->payload['object']['message']['attachments'] = [[
            'type' => 'doc',
            'doc' => ['title' => 'proof.pdf', 'url' => 'https://example.com/proof.pdf'],
        ]];

        (new VkMessageService(VkUpdateDtoMock::getDto($this->payload)))->handleUpdate();

        Queue::assertPushed(SendAiDraftJob::class, fn ($job) => $job->userMessage === 'Please inspect this file');
    }
}
