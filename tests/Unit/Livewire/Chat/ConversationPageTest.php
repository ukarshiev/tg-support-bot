<?php

namespace Tests\Unit\Livewire\Chat;

use App\Livewire\Chat\ConversationPage;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\User;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Unit tests for the standalone Livewire Chat ConversationPage workspace.
 *
 * Tests cover lifecycle methods — mount, dialog list, selectChat, sendReply,
 * polling interval, and shouldShowReplyForm.
 */
class ConversationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    public function test_can_render_page(): void
    {
        Livewire::test(ConversationPage::class)
            ->assertSuccessful();
    }

    // ── mount ──────────────────────────────────────────────────────────────────

    public function test_mount_loads_empty_collections(): void
    {
        $component = Livewire::test(ConversationPage::class);

        $component
            ->assertSet('activeBotUserId', null)
            ->assertSet('activeBotUser', null);

        $this->assertTrue($component->get('chatMessages')->isEmpty());
    }

    public function test_mount_populates_dialog_list(): void
    {
        BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class);

        $this->assertTrue($component->get('dialogList')->isNotEmpty());
    }

    // ── selectChat ─────────────────────────────────────────────────────────────

    public function test_select_chat_sets_active_bot_user(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('activeBotUserId', $botUser->id)
            ->assertSet('activeBotUser.id', $botUser->id);
    }

    public function test_select_chat_loads_messages(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Hello',
        ]);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id);

        $this->assertCount(1, $component->get('chatMessages'));
    }

    public function test_select_chat_zero_clears_active_dialog(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('selectChat', 0)
            ->assertSet('activeBotUserId', null)
            ->assertSet('activeBotUser', null)
            ->assertSet('chatMessages', fn ($msgs) => $msgs->isEmpty());
    }

    // ── sendReply ──────────────────────────────────────────────────────────────

    public function test_send_reply_saves_message_and_dispatches_job(): void
    {
        Queue::fake();
        config(['app.manager_interface' => 'admin_panel']);

        $botUser = BotUser::create(['chat_id' => 100, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Hello!')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertNotified('Сообщение отправлено');

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Hello!',
        ]);

        Queue::assertPushed(SendTelegramSimpleQueryJob::class);
    }

    public function test_send_reply_does_nothing_outside_admin_panel_mode(): void
    {
        Queue::fake();
        config(['app.manager_interface' => 'telegram_group']);

        $botUser = BotUser::create(['chat_id' => 100, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Hello!')
            ->call('sendReply');

        $this->assertDatabaseMissing('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
        ]);

        Queue::assertNothingPushed();
    }

    // ── Polling interval ───────────────────────────────────────────────────────

    public function test_polling_interval_is_five_seconds(): void
    {
        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertEquals('5s', $instance->getPollingInterval());
    }

    // ── shouldShowReplyForm ────────────────────────────────────────────────────

    public function test_should_show_reply_form_returns_true_in_admin_panel_mode(): void
    {
        config(['app.manager_interface' => 'admin_panel']);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertTrue($instance->shouldShowReplyForm());
    }

    public function test_should_show_reply_form_returns_false_in_telegram_group_mode(): void
    {
        config(['app.manager_interface' => 'telegram_group']);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->shouldShowReplyForm());
    }

    // ── insertQuickReply ──────────────────────────────────────────────────────

    public function test_insert_quick_reply_sets_reply_text(): void
    {
        Livewire::test(ConversationPage::class)
            ->call('insertQuickReply', 'Ожидайте, пожалуйста')
            ->assertSet('replyText', 'Ожидайте, пожалуйста');
    }

    // ── getImageAttachments ──────────────────────────────────────────────────

    public function test_get_image_attachments_returns_empty_without_active_dialog(): void
    {
        $component = Livewire::test(ConversationPage::class);

        $this->assertTrue($component->instance()->getImageAttachments()->isEmpty());
    }
}
