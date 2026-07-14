<?php

namespace Tests\Unit\Modules\Max\Services;

use App\Models\BotUser;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiDraftJob;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Max\Jobs\MirrorMaxIncomingMessageJob;
use App\Modules\Max\Services\MaxMessageService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Mocks\Max\MaxUpdateDtoMock;
use Tests\TestCase;

class MaxMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    private BotUser $botUser;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->payload = MaxUpdateDtoMock::getDtoParams();
        $this->botUser = BotUser::getUserByChatId(
            $this->payload['message']['sender']['user_id'],
            'max',
        );
    }

    public function test_multiple_attachments_create_one_message_and_one_mirror_job(): void
    {
        $this->payload['message']['body']['text'] = 'One caption';
        $this->payload['message']['body']['attachments'] = [
            ['type' => 'image', 'payload' => ['url' => 'https://example.com/one.jpg']],
            ['type' => 'image', 'payload' => ['url' => 'https://example.com/two.jpg']],
            ['type' => 'file', 'payload' => ['url' => 'https://example.com/proof.pdf', 'filename' => 'proof.pdf']],
        ];
        $dto = MaxUpdateDtoMock::getDto($this->payload);

        (new MaxMessageService($dto))->handleUpdate();

        $message = Message::where('bot_user_id', $this->botUser->id)->sole();
        $this->assertSame($dto->persistenceId(), (int) $message->from_id);
        $this->assertNotSame($dto->from_id, (int) $message->from_id);
        $this->assertCount(3, $message->attachments);
        Queue::assertPushed(MirrorMaxIncomingMessageJob::class, 1);
        Queue::assertPushed(MirrorMaxIncomingMessageJob::class, fn ($job) => $job->externalMessageId === $dto->id);
    }

    public function test_repeated_delivery_does_not_duplicate_message_or_attachments(): void
    {
        $this->payload['message']['body']['attachments'] = [
            ['type' => 'image', 'payload' => ['url' => 'https://example.com/one.jpg']],
            ['type' => 'file', 'payload' => ['url' => 'https://example.com/proof.pdf', 'filename' => 'proof.pdf']],
        ];
        $dto = MaxUpdateDtoMock::getDto($this->payload);

        (new MaxMessageService($dto))->handleUpdate();
        (new MaxMessageService($dto))->handleUpdate();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_attachments', 2);
    }

    public function test_ai_is_blocked_until_language_is_selected(): void
    {
        app(SettingsService::class)->set('ai.enabled', true);

        (new MaxMessageService(MaxUpdateDtoMock::getDto($this->payload)))->handleUpdate();

        Queue::assertNotPushed(SendAiDraftJob::class);
        Queue::assertNotPushed(SendAiReplyJob::class);
    }

    public function test_media_without_caption_creates_ai_draft_after_language_selection(): void
    {
        app(SettingsService::class)->set('ai.enabled', true);
        $this->botUser->update(['preferred_language_code' => 'en']);
        $this->payload['message']['body']['text'] = null;
        $this->payload['message']['body']['attachments'] = [
            ['type' => 'image', 'payload' => ['url' => 'https://example.com/one.jpg']],
        ];

        (new MaxMessageService(MaxUpdateDtoMock::getDto($this->payload)))->handleUpdate();

        Queue::assertPushed(SendAiDraftJob::class, fn ($job) => str_contains($job->userMessage, 'медиафайл'));
        Queue::assertNotPushed(SendAiReplyJob::class);
    }
}
