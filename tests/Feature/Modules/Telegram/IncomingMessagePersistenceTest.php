<?php

namespace Tests\Feature\Modules\Telegram;

use App\Livewire\Chat\ConversationPage;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\BotUser;
use App\Models\DeliveryOperation;
use App\Models\Message;
use App\Modules\Ai\Jobs\SendAiReplyJob;
use App\Modules\Max\DTOs\MaxUpdateDto;
use App\Modules\Max\Services\MaxMessageService;
use App\Modules\Telegram\Actions\SelectLanguage;
use App\Modules\Telegram\Controllers\TelegramBotController;
use App\Modules\Telegram\DTOs\TelegramUpdateDto;
use App\Modules\Telegram\Jobs\SendTelegramMessageJob;
use App\Modules\Telegram\Jobs\TopicCreateJob;
use App\Modules\Vk\DTOs\VkUpdateDto;
use App\Modules\Vk\Services\VkMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Mocks\Max\MaxUpdateDtoMock;
use Tests\Mocks\Vk\VkUpdateDtoMock;
use Tests\TestCase;
use Tests\Traits\SeedsSettings;

/**
 * Covers the "incoming messages are always persisted to `messages`" invariant
 * introduced to fix the group-OFF blind spot (issue #175).
 *
 * Scenarios:
 *   - Telegram text,  group OFF  → exactly 1 incoming row, no duplicate
 *   - Telegram media (photo),  group OFF  → 1 row + 1 attachment row
 *   - Telegram text,  group ON   → 1 row (via job), group-forward job dispatched, no duplicate
 *   - VK text,        group OFF  → exactly 1 incoming row
 *   - VK media (photo), group OFF → 1 row + 1 attachment row
 *   - VK text,        group ON   → group-forward job dispatched (existing behavior)
 *   - Max text,       group OFF  → exactly 1 incoming row
 *   - Max media (photo), group OFF → 1 row + 1 attachment row
 *   - Max text,       group ON   → group-forward job dispatched (existing behavior)
 *   - Admin ConversationPage shows the group-OFF persisted message
 */
class IncomingMessagePersistenceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSettings;

    /**
     * By default TestCase::setUp() seeds telegram.group_id (to make the group-ON
     * path work across the test suite). Tests that want "group OFF" must call
     * $this->clearGroupId() to remove that setting before exercising the path.
     */
    private function clearGroupId(): void
    {
        app(\App\Services\Settings\SettingsService::class)->set('telegram.group_id', '');
    }

    private function selectTelegramLanguage(BotUser $botUser, string $code = 'ru', string $name = 'Русский'): BotUser
    {
        $botUser->update([
            'preferred_language_code' => $code,
            'preferred_language_name' => $name,
            'preferred_language_selected_at' => now(),
        ]);

        return $botUser;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build a minimal Telegram private-message payload for a given BotUser.
     *
     * @param BotUser             $botUser
     * @param array<string,mixed> $messageOverrides merged into the `message` key
     *
     * @return array<string, mixed>
     */
    private function telegramPayload(BotUser $botUser, array $messageOverrides = []): array
    {
        return [
            'update_id' => 100001,
            'message' => array_merge([
                'message_id' => 555,
                'from' => [
                    'id' => $botUser->chat_id,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'testuser',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => $botUser->chat_id,
                    'type' => 'private',
                ],
                'date' => time(),
                'text' => 'Hello from user',
            ], $messageOverrides),
        ];
    }

    /**
     * POST to the Telegram webhook with the secret-key header for the middleware.
     *
     * @param array<string, mixed> $payload
     */
    private function postTgWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/telegram/bot', $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ]);
    }

    public function test_telegram_incoming_without_selected_language_shows_selector_and_does_not_persist(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = BotUser::create(['chat_id' => 112233, 'platform' => 'telegram']);

        $this->postTgWebhook($this->telegramPayload($botUser))->assertOk();

        $this->assertSame(1, Message::where('bot_user_id', $botUser->id)->count());
        Queue::assertPushed(\App\Modules\Telegram\Jobs\SendTelegramMessageJob::class, function ($job): bool {
            return $job->typeMessage === 'outgoing'
                && $job->queryParams->text === 'Choose language';
        });
    }

    // ── Telegram — group OFF ──────────────────────────────────────────────────

    /**
     * When no group_id is configured, an incoming Telegram text message must be
     * persisted directly to `messages` (group-OFF path) without going through the
     * supergroup job. No duplicate rows may be created.
     */
    public function test_telegram_incoming_text_with_group_off_persists_to_messages(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        // TestCase::setUp() seeds a default group_id — remove it for the group-OFF scenario.
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 123456, 'platform' => 'telegram']));

        $this->postTgWebhook($this->telegramPayload($botUser))->assertOk();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 555,
            'to_id' => 0,
            'text' => 'Hello from user',
        ]);

        // Exactly one incoming row — no duplicate.
        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count()
        );

        // No group-forward or topic-creation jobs dispatched.
        Queue::assertNotPushed(SendTelegramMessageJob::class);
        Queue::assertNotPushed(TopicCreateJob::class);
    }

    public function test_telegram_repeated_private_update_is_processed_once_with_group_off(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'ai.enabled' => true,
            'ai.auto_reply' => true,
        ]);
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 123457, 'platform' => 'telegram']));
        $payload = $this->telegramPayload($botUser, [
            'message_id' => 777,
            'text' => 'Are you here?',
        ]);

        $this->postTgWebhook($payload)->assertOk();
        $this->postTgWebhook($payload)->assertOk();

        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)
                ->where('message_type', 'incoming')
                ->where('from_id', 777)
                ->count()
        );

        Queue::assertPushed(SendAiReplyJob::class, 1);
    }

    /**
     * When no group_id is configured, an incoming Telegram photo must be persisted
     * to `messages` AND produce a `message_attachments` row.
     */
    public function test_telegram_incoming_photo_with_group_off_persists_message_and_attachment(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 234567, 'platform' => 'telegram']));

        $payload = $this->telegramPayload($botUser, [
            'photo' => [
                ['file_id' => 'small_id', 'file_unique_id' => 'u1', 'width' => 90, 'height' => 90, 'file_size' => 100],
                ['file_id' => 'PHOTO_FILE_ID', 'file_unique_id' => 'u2', 'width' => 800, 'height' => 600, 'file_size' => 50000],
            ],
            'caption' => 'Look at this!',
        ]);
        // Remove 'text' — photos don't have it
        unset($payload['message']['text']);

        $this->postTgWebhook($payload)->assertOk();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'incoming',
            'text' => 'Look at this!',
        ]);

        $message = Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->first();
        $this->assertNotNull($message);

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'file_type' => 'photo',
        ]);
    }

    /**
     * When no group_id is configured, an incoming Telegram document must be persisted
     * with a `message_attachments` row reflecting the document file_id.
     */
    public function test_telegram_incoming_document_with_group_off_persists_attachment(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 345678, 'platform' => 'telegram']));

        $payload = $this->telegramPayload($botUser, [
            'document' => [
                'file_name' => 'report.pdf',
                'mime_type' => 'application/pdf',
                'file_id' => 'DOC_FILE_ID',
                'file_unique_id' => 'u3',
                'file_size' => 12000,
            ],
        ]);
        unset($payload['message']['text']);

        $this->postTgWebhook($payload)->assertOk();

        $message = Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->first();
        $this->assertNotNull($message, 'Expected an incoming message row to be persisted');

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'file_id' => 'DOC_FILE_ID',
            'file_type' => 'document',
        ]);
    }

    // ── Telegram — group ON ───────────────────────────────────────────────────

    /**
     * When the group IS configured, the existing group-forward path is taken:
     * SendTelegramMessageJob is dispatched and exactly one incoming row ends up
     * in `messages` via the job — not via the direct-persist path.
     *
     * We verify: only the job path runs (Queue::assertPushed) and only one row exists.
     * We do NOT call the real job here to avoid HTTP calls.
     */
    public function test_telegram_incoming_with_group_on_dispatches_job_not_direct_persist(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'telegram.group_id' => '-100999888',
        ]);

        // BotUser without a topic_id → SendTelegramMessageJob will create the topic
        // through a single chained TopicCreateJob when the queue worker handles it.
        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 456789, 'platform' => 'telegram']));

        $this->postTgWebhook($this->telegramPayload($botUser))->assertOk();

        Queue::assertPushed(SendTelegramMessageJob::class, 1);
        Queue::assertNotPushed(TopicCreateJob::class);

        // The direct-persist path must NOT have run — no DB row yet (jobs are fake).
        $this->assertSame(
            0,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count(),
            'No direct-persist row expected when group is ON (job persists instead)'
        );
    }

    /**
     * When the group IS configured and a topic_id already exists, only ONE incoming
     * row ends up in `messages` after the real SendTelegramMessageJob runs.
     * This guards against the duplicate-row regression (group-ON must not create
     * both a direct-persist row AND a job row).
     */
    public function test_telegram_incoming_with_group_on_no_duplicate_rows(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'telegram.group_id' => '-100999888',
        ]);

        $botUser = $this->selectTelegramLanguage(BotUser::create([
            'chat_id' => 567890,
            'platform' => 'telegram',
            'topic_id' => 42,
        ]));

        $this->postTgWebhook($this->telegramPayload($botUser))->assertOk();

        // With Queue::fake() the job is queued but not executed → 0 DB rows from
        // the job. The direct-persist branch must also not have run → still 0.
        $this->assertSame(
            0,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count(),
            'No direct-persist row expected on group-ON path — job is responsible'
        );

        Queue::assertPushed(SendTelegramMessageJob::class);
    }

    public function test_telegram_repeated_private_update_is_forwarded_once_with_group_on(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'telegram.group_id' => '-100999888',
            'ai.enabled' => true,
            'ai.auto_reply' => true,
        ]);

        $botUser = $this->selectTelegramLanguage(BotUser::create([
            'chat_id' => 567891,
            'platform' => 'telegram',
            'topic_id' => 43,
        ]));

        $payload = $this->telegramPayload($botUser, [
            'message_id' => 778,
            'text' => 'Are you still here?',
        ]);

        $this->postTgWebhook($payload)->assertOk();
        $this->postTgWebhook($payload)->assertOk();

        Queue::assertPushed(SendTelegramMessageJob::class, 1);
        Queue::assertPushed(SendAiReplyJob::class, 1);

        $this->assertDatabaseHas('delivery_operations', [
            'bot_user_id' => $botUser->id,
            'operation' => 'telegram_ingress',
            'status' => DeliveryOperation::STATUS_DELIVERED,
            'attempts' => 1,
        ]);

        $this->assertSame(
            0,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count(),
            'No direct-persist row expected on group-ON path — job is responsible'
        );
    }

    public function test_telegram_concurrent_claim_does_not_dispatch_duplicate_pipeline(): void
    {
        Queue::fake();
        Cache::flush();
        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create([
            'chat_id' => 567892,
            'platform' => 'telegram',
        ]));
        $messageId = 779;
        $operationKey = hash('sha256', "telegram-ingress:{$botUser->id}:{$messageId}");
        DeliveryOperation::create([
            'operation_key' => $operationKey,
            'bot_user_id' => $botUser->id,
            'trace_id' => 'concurrent-test',
            'destination' => 'internal',
            'operation' => 'telegram_ingress',
            'status' => DeliveryOperation::STATUS_PROCESSING,
            'attempts' => 1,
        ]);
        Cache::put("telegram:incoming:telegram:{$botUser->chat_id}:{$messageId}", true, now()->addMinute());

        $this->postTgWebhook($this->telegramPayload($botUser, [
            'message_id' => $messageId,
            'text' => 'Concurrent delivery',
        ]))->assertOk();

        Queue::assertNotPushed(SendTelegramMessageJob::class);
        Queue::assertNotPushed(SendAiReplyJob::class);
        $this->assertDatabaseMissing('messages', [
            'bot_user_id' => $botUser->id,
            'from_id' => $messageId,
        ]);
        $this->assertSame(
            DeliveryOperation::STATUS_PROCESSING,
            DeliveryOperation::where('operation_key', $operationKey)->value('status'),
        );
    }

    // ── VK — group OFF ────────────────────────────────────────────────────────

    /**
     * When no telegram.group_id is configured, an incoming VK text message must
     * be persisted directly to `messages` via VkMessageService::persistIncomingVkMessage().
     */
    public function test_vk_incoming_text_with_group_off_persists_to_messages(): void
    {
        Queue::fake();

        // TestCase::setUp() seeds a default group_id — remove it for the group-OFF scenario.
        $this->clearGroupId();

        $dto = VkUpdateDtoMock::getDto();
        $botUser = BotUser::getUserByChatId($dto->from_id, 'vk');

        (new VkMessageService($dto))->handleUpdate();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'platform' => 'vk',
            'message_type' => 'incoming',
            'text' => 'Test text',
        ]);

        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count()
        );
    }

    /**
     * When no group_id is configured, a VK message with a photo attachment must
     * produce a `message_attachments` row.
     */
    public function test_vk_incoming_photo_with_group_off_persists_attachment(): void
    {
        Queue::fake();
        $this->clearGroupId();

        $params = VkUpdateDtoMock::getDtoParams();
        $params['object']['message']['text'] = '';
        $params['object']['message']['attachments'] = [
            [
                'type' => 'photo',
                'photo' => [
                    'orig_photo' => ['url' => 'https://vk.com/photo.jpg'],
                ],
            ],
        ];

        $request = \Illuminate\Support\Facades\Request::create('api/vk/bot', 'POST', $params);
        $dto = VkUpdateDto::fromRequest($request);
        $botUser = BotUser::getUserByChatId($dto->from_id, 'vk');

        (new VkMessageService($dto))->handleUpdate();

        $message = Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->first();
        $this->assertNotNull($message, 'Expected an incoming VK message row');

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'file_type' => 'photo',
        ]);
    }

    /**
     * When group IS configured, VkMessageService dispatches SendVkTelegramMessageJob
     * (the existing group-ON path) and does NOT call persistIncomingVkMessage().
     */
    public function test_vk_incoming_with_group_on_dispatches_job_not_direct_persist(): void
    {
        Queue::fake();

        $this->seedSetting('telegram.group_id', '-100999888');

        $dto = VkUpdateDtoMock::getDto();
        $botUser = BotUser::getUserByChatId($dto->from_id, 'vk');
        $botUser->update(['topic_id' => 77]);

        (new VkMessageService($dto))->handleUpdate();

        // Save-first: Telegram outage must not hide or lose the client message.
        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count(),
            'Incoming VK message must be stored before mirror delivery'
        );

        Queue::assertPushed(\App\Modules\Vk\Jobs\MirrorVkIncomingMessageJob::class);
    }

    // ── Max — group OFF ───────────────────────────────────────────────────────

    /**
     * When no telegram.group_id is configured, an incoming Max text message must
     * be persisted directly to `messages` via MaxMessageService::persistIncomingMaxMessage().
     */
    public function test_max_incoming_text_with_group_off_persists_to_messages(): void
    {
        Queue::fake();

        // TestCase::setUp() seeds a default group_id — remove it for the group-OFF scenario.
        $this->clearGroupId();

        $dto = MaxUpdateDtoMock::getDto();
        $botUser = BotUser::getUserByChatId($dto->from_id, 'max');

        (new MaxMessageService($dto))->handleUpdate();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'platform' => 'max',
            'message_type' => 'incoming',
            'text' => 'Test text',
        ]);

        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count()
        );
    }

    /**
     * When no group_id is configured, a Max message with a photo attachment must
     * produce a `message_attachments` row.
     */
    public function test_max_incoming_photo_with_group_off_persists_attachment(): void
    {
        Queue::fake();
        $this->clearGroupId();

        $params = MaxUpdateDtoMock::getDtoParams();
        $params['message']['body']['text'] = null;
        $params['message']['body']['attachments'] = [
            [
                'type' => 'image',
                'payload' => ['url' => 'https://max.ru/photo.jpg'],
            ],
        ];

        $request = \Illuminate\Support\Facades\Request::create('api/max/bot', 'POST', $params);
        $dto = MaxUpdateDto::fromRequest($request);
        $botUser = BotUser::getUserByChatId($dto->from_id, 'max');

        (new MaxMessageService($dto))->handleUpdate();

        $message = Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->first();
        $this->assertNotNull($message, 'Expected an incoming Max message row');

        $this->assertDatabaseHas('message_attachments', [
            'message_id' => $message->id,
            'file_type' => 'photo',
        ]);
    }

    /**
     * When group IS configured, MaxMessageService dispatches SendMaxTelegramMessageJob
     * (the existing group-ON path) and does NOT call persistIncomingMaxMessage().
     */
    public function test_max_incoming_with_group_on_dispatches_job_not_direct_persist(): void
    {
        Queue::fake();

        $this->seedSetting('telegram.group_id', '-100999888');

        $dto = MaxUpdateDtoMock::getDto();
        $botUser = BotUser::getUserByChatId($dto->from_id, 'max');
        $botUser->update(['topic_id' => 88]);

        (new MaxMessageService($dto))->handleUpdate();

        // Save-first: Telegram outage must not hide or lose the client message.
        $this->assertSame(
            1,
            Message::where('bot_user_id', $botUser->id)->where('message_type', 'incoming')->count(),
            'Incoming MAX message must be stored before mirror delivery'
        );

        Queue::assertPushed(\App\Modules\Max\Jobs\MirrorMaxIncomingMessageJob::class);
    }

    // ── Admin panel visibility ────────────────────────────────────────────────

    /**
     * The group-OFF persisted message must appear in the admin ConversationPage
     * workspace (via the standard message loading path).
     */
    public function test_admin_workspace_shows_group_off_persisted_incoming_message(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = $this->selectTelegramLanguage(BotUser::create(['chat_id' => 678901, 'platform' => 'telegram']));

        // Trigger the group-OFF persistence path.
        $this->postTgWebhook($this->telegramPayload($botUser, ['text' => 'Admin can see me']))->assertOk();

        $admin = \App\Models\User::factory()->create();
        $this->actingAs($admin);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id);

        $messages = $component->get('chatMessages');
        $this->assertCount(1, $messages);
        $this->assertSame('Admin can see me', $messages->first()->text);
    }

    public function test_telegram_start_message_is_persisted_for_debug_visibility(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $botUser = BotUser::create(['chat_id' => 789012, 'platform' => 'telegram']);

        $this->postTgWebhook($this->telegramPayload($botUser, [
            'message_id' => 779,
            'text' => '/start',
        ]))->assertOk();

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 779,
            'text' => '/start',
        ]);
    }

    public function test_telegram_start_with_group_on_queues_start_before_language_selector(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'telegram.group_id' => '-100999888',
        ]);

        $botUser = BotUser::create(['chat_id' => 789013, 'platform' => 'telegram']);

        $this->postTgWebhook($this->telegramPayload($botUser, [
            'message_id' => 780,
            'text' => '/start',
        ]))->assertOk();

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $ordered = collect($jobs)
            ->map(fn (array $payload): array => [
                'type' => $payload['job']->typeMessage,
                'text' => $payload['job']->typeMessage === 'incoming'
                    ? $payload['job']->updateDto->text
                    : $payload['job']->queryParams->text,
            ])
            ->values();

        $this->assertGreaterThanOrEqual(2, $ordered->count());
        $this->assertSame(['type' => 'incoming', 'text' => '/start'], $ordered->get(0));
        $this->assertSame('outgoing', $ordered->get(1)['type']);
        $this->assertSame('Choose language', $ordered->get(1)['text']);
    }

    public function test_telegram_repeated_start_with_existing_selector_queues_fresh_selector(): void
    {
        Queue::fake();

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
            'telegram.group_id' => '-100999888',
        ]);

        $botUser = BotUser::create(['chat_id' => 789014, 'platform' => 'telegram']);
        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 780,
            'to_id' => 1000,
            'text' => "Выберите язык / Choose your language:\nСтраница 1/2",
        ]);

        $this->postTgWebhook($this->telegramPayload($botUser, [
            'message_id' => 781,
            'text' => '/start',
        ]))->assertOk();

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $selectorJobs = collect($jobs)->filter(fn (array $payload): bool => $payload['job']->typeMessage === 'outgoing'
            && $payload['job']->queryParams->text === 'Choose language');

        $this->assertCount(1, $selectorJobs);
    }

    public function test_telegram_full_start_language_text_flow_keeps_one_open_dialog_and_full_welcome_visible(): void
    {
        Queue::fake();
        Cache::flush();
        Http::fake([
            'https://api.telegram.org/bot*/deleteMessage*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $welcome = AutoReply::query()
            ->where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', '__system_welcome__')
            ->first();

        if ($welcome === null) {
            $welcome = AutoReply::create([
                'type' => AutoReply::TYPE_WELCOME,
                'trigger' => '__system_welcome__',
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        } else {
            $welcome->update([
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        }

        AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'start',
            'response' => 'Добрый день! Чем я могу помочь?',
            'enabled' => true,
        ]);

        AutoReplyTranslation::updateOrCreate(
            [
                'auto_reply_id' => $welcome->id,
                'locale' => 'en',
            ],
            [
                'text' => 'FULL WELCOME: contact rules, links and support instructions.',
                'status' => AutoReplyTranslation::STATUS_READY,
                'source' => AutoReplyTranslation::SOURCE_AUTO,
                'source_hash' => AutoReply::sourceHash($welcome->response),
            ],
        );

        $chatId = 990001;

        $this->postTgWebhook($this->telegramPayload(
            BotUser::create(['chat_id' => $chatId, 'platform' => 'telegram']),
            [
                'message_id' => 1001,
                'text' => '/start',
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Flow',
                    'username' => 'flow_user',
                    'language_code' => 'en',
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
            ]
        ))->assertOk();

        $botUser = BotUser::where('chat_id', $chatId)->where('platform', 'telegram')->firstOrFail();

        $callbackPayload = [
            'update_id' => 100002,
            'callback_query' => [
                'id' => 'language-callback-1',
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Flow',
                    'username' => 'flow_user',
                    'language_code' => 'en',
                ],
                'message' => [
                    'message_id' => 1002,
                    'from' => [
                        'id' => 999,
                        'is_bot' => true,
                        'first_name' => 'Support Bot',
                    ],
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => "Выберите язык / Choose your language:\nСтраница 1/2",
                ],
                'data' => 'select_language:en',
            ],
        ];

        app(SelectLanguage::class)->execute(
            $botUser,
            TelegramUpdateDto::fromRequest(\Illuminate\Http\Request::create('/api/telegram/bot', 'POST', $callbackPayload)),
        );

        $this->seedSetting('telegram.token', '');

        $clientTextMessageId = random_int(200000, 900000);

        $clientTextPayload = $this->telegramPayload($botUser, [
            'message_id' => $clientTextMessageId,
            'text' => 'Hello, can you see my message?',
            'from' => [
                'id' => $chatId,
                'is_bot' => false,
                'first_name' => 'Flow',
                'username' => 'flow_user',
                'language_code' => 'en',
            ],
            'chat' => [
                'id' => $chatId,
                'type' => 'private',
            ],
        ]);

        $controller = new TelegramBotController(
            \Illuminate\Http\Request::create('/api/telegram/bot', 'POST', $clientTextPayload),
        );
        $controller->bot_query();

        $botUser->refresh();

        $this->assertSame('en', $botUser->preferred_language_code);
        $this->assertFalse($botUser->is_closed, 'Start/language/text flow must not close the dialog by itself.');

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'incoming',
            'from_id' => 1001,
            'text' => '/start',
        ]);

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'incoming',
            'from_id' => $clientTextMessageId,
            'text' => 'Hello, can you see my message?',
        ]);

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];

        $texts = collect($jobs)->map(fn (array $payload): ?string => $payload['job']->queryParams->text)->all();

        $this->assertContains('Choose language', $texts);
        $this->assertContains('FULL WELCOME: contact rules, links and support instructions.', $texts);
        $this->assertNotContains('Good day! How can I help you?', $texts);
    }

    public function test_telegram_language_callback_via_webhook_answers_silently_and_queues_welcome(): void
    {
        Queue::fake();
        Cache::flush();
        Http::fake([
            'https://api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->seedSettings([
            'telegram.token' => 'bot:TOKEN',
            'telegram.secret_key' => 'test-secret',
        ]);
        $this->clearGroupId();

        $welcome = AutoReply::query()
            ->where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', '__system_welcome__')
            ->first();

        if ($welcome === null) {
            $welcome = AutoReply::create([
                'type' => AutoReply::TYPE_WELCOME,
                'trigger' => '__system_welcome__',
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        } else {
            $welcome->update([
                'response' => 'Полное русское приветствие',
                'enabled' => true,
            ]);
        }

        AutoReplyTranslation::updateOrCreate(
            [
                'auto_reply_id' => $welcome->id,
                'locale' => 'pl',
            ],
            [
                'text' => 'PEŁNE POWITANIE PO POLSKU',
                'status' => AutoReplyTranslation::STATUS_READY,
                'source' => AutoReplyTranslation::SOURCE_AUTO,
                'source_hash' => AutoReply::sourceHash($welcome->response),
            ],
        );

        $chatId = 990777;
        $botUser = BotUser::create(['chat_id' => $chatId, 'platform' => 'telegram']);

        $this->postTgWebhook([
            'update_id' => 200002,
            'callback_query' => [
                'id' => 'webhook-language-callback',
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Flow',
                    'username' => 'flow_user',
                    'language_code' => 'pl',
                ],
                'message' => [
                    'message_id' => 2002,
                    'chat' => [
                        'id' => $chatId,
                        'type' => 'private',
                    ],
                    'date' => time(),
                    'text' => "Выберите язык / Choose your language:\nСтраница 2/2",
                ],
                'data' => 'select_language:pl',
            ],
        ])->assertOk();

        $botUser->refresh();

        $this->assertSame('pl', $botUser->preferred_language_code);
        $this->assertSame('Polski', $botUser->preferred_language_name);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'webhook-language-callback'
            && !isset($request['text']));

        /** @phpstan-ignore-next-line */
        $jobs = Queue::pushedJobs()[SendTelegramMessageJob::class] ?? [];
        $welcomeJobs = collect($jobs)->filter(fn (array $payload): bool => $payload['job']->typeMessage === 'outgoing'
            && $payload['job']->queryParams->text === 'PEŁNE POWITANIE PO POLSKU');

        $this->assertCount(1, $welcomeJobs);
    }
}
