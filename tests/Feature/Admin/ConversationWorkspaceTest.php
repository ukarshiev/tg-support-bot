<?php

namespace Tests\Feature\Admin;

use App\Livewire\Chat\ConversationPage;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Modules\Admin\Actions\SendReplyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for the 3-column manager chat workspace (ConversationPage).
 *
 * Covers:
 *  - Page renders under auth with MANAGER_INTERFACE=admin_panel
 *  - Dialog list populates with BotUsers
 *  - Search filter narrows the dialog list
 *  - Status-filter tabs (all / open / closed)
 *  - Selecting a dialog loads its messages and user panel
 *  - Sending a reply calls SendReplyAction and refreshes
 *  - Quick-reply insertion
 *  - Media gallery returns image attachments
 *  - Reply form hidden in telegram_group mode
 */
class ConversationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        config(['app.manager_interface' => 'admin_panel']);
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    public function test_page_renders_under_auth(): void
    {
        Livewire::test(ConversationPage::class)
            ->assertSuccessful();
    }

    public function test_page_shows_empty_state_when_no_dialog_selected(): void
    {
        Livewire::test(ConversationPage::class)
            ->assertSee('Выберите диалог');
    }

    // ── Dialog list ────────────────────────────────────────────────────────────

    public function test_dialog_list_contains_bot_users(): void
    {
        BotUser::create(['chat_id' => '111', 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class);

        $component->assertSet('dialogList', fn ($list) => $list->isNotEmpty());
    }

    public function test_dialog_list_ordered_by_last_message_desc(): void
    {
        $older = BotUser::create(['chat_id' => '200', 'platform' => 'telegram']);
        $newer = BotUser::create(['chat_id' => '201', 'platform' => 'telegram']);

        // Use DB::table() to bypass Eloquent timestamps management
        // and set created_at to controlled values for deterministic ordering.
        \Illuminate\Support\Facades\DB::table('messages')->insert([
            'bot_user_id' => $older->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Old message',
            'created_at' => '2024-01-01 10:00:00',
            'updated_at' => '2024-01-01 10:00:00',
        ]);

        \Illuminate\Support\Facades\DB::table('messages')->insert([
            'bot_user_id' => $newer->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'New message',
            'created_at' => '2024-06-01 10:00:00',
            'updated_at' => '2024-06-01 10:00:00',
        ]);

        $component = Livewire::test(ConversationPage::class);

        $component->assertSet('dialogList', function ($list) use ($newer) {
            return $list->first()?->id === $newer->id;
        });
    }

    // ── Search ─────────────────────────────────────────────────────────────────

    public function test_search_filters_dialog_list_by_chat_id(): void
    {
        BotUser::create(['chat_id' => '12345', 'platform' => 'telegram']);
        BotUser::create(['chat_id' => '99999', 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->set('search', '123')
            ->assertSet('dialogList', fn ($list) => $list->count() === 1
                && (string) $list->first()->chat_id === '12345');
    }

    public function test_empty_search_shows_all_users(): void
    {
        BotUser::create(['chat_id' => '11111', 'platform' => 'telegram']);
        BotUser::create(['chat_id' => '22222', 'platform' => 'vk']);

        Livewire::test(ConversationPage::class)
            ->set('search', '')
            ->assertSet('dialogList', fn ($list) => $list->count() === 2);
    }

    // ── Status filter ──────────────────────────────────────────────────────────

    public function test_status_filter_open_excludes_closed_dialogs(): void
    {
        BotUser::create(['chat_id' => '300', 'platform' => 'telegram', 'is_closed' => false]);
        BotUser::create(['chat_id' => '301', 'platform' => 'telegram', 'is_closed' => true]);

        Livewire::test(ConversationPage::class)
            ->set('statusFilter', 'open')
            ->assertSet('dialogList', fn ($list) => $list->count() === 1
                && (string) $list->first()->chat_id === '300');
    }

    public function test_status_filter_closed_excludes_open_dialogs(): void
    {
        BotUser::create(['chat_id' => '400', 'platform' => 'telegram', 'is_closed' => false]);
        BotUser::create(['chat_id' => '401', 'platform' => 'telegram', 'is_closed' => true]);

        Livewire::test(ConversationPage::class)
            ->set('statusFilter', 'closed')
            ->assertSet('dialogList', fn ($list) => $list->count() === 1
                && (string) $list->first()->chat_id === '401');
    }

    public function test_status_filter_all_shows_both(): void
    {
        BotUser::create(['chat_id' => '500', 'platform' => 'telegram', 'is_closed' => false]);
        BotUser::create(['chat_id' => '501', 'platform' => 'telegram', 'is_closed' => true]);

        Livewire::test(ConversationPage::class)
            ->set('statusFilter', 'all')
            ->assertSet('dialogList', fn ($list) => $list->count() === 2);
    }

    // ── Select dialog ──────────────────────────────────────────────────────────

    public function test_select_chat_loads_messages(): void
    {
        $botUser = BotUser::create(['chat_id' => '600', 'platform' => 'telegram']);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Hello!',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('activeBotUserId', $botUser->id)
            ->assertSet('chatMessages', fn ($msgs) => $msgs->count() === 1);
    }

    public function test_select_chat_sets_active_bot_user(): void
    {
        $botUser = BotUser::create(['chat_id' => '700', 'platform' => 'vk']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('activeBotUser', fn ($u) => $u instanceof BotUser && $u->id === $botUser->id);
    }

    public function test_select_chat_zero_clears_active_dialog(): void
    {
        $botUser = BotUser::create(['chat_id' => '800', 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('selectChat', 0)
            ->assertSet('activeBotUserId', null)
            ->assertSet('activeBotUser', null)
            ->assertSet('chatMessages', fn ($msgs) => $msgs->isEmpty());
    }

    public function test_messages_ordered_by_created_at_asc(): void
    {
        $botUser = BotUser::create(['chat_id' => '900', 'platform' => 'telegram']);

        $first = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'First',
            'created_at' => now()->subMinutes(10),
        ]);

        $second = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 1,
            'text' => 'Second',
            'created_at' => now(),
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('chatMessages', function ($msgs) use ($first, $second) {
                return $msgs->first()->id === $first->id
                    && $msgs->last()->id === $second->id;
            });
    }

    // ── Send reply ─────────────────────────────────────────────────────────────

    public function test_send_reply_calls_send_reply_action(): void
    {
        Queue::fake();

        $botUser = BotUser::create(['chat_id' => '1000', 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Test reply from workspace')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertNotified('Сообщение отправлено');

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Test reply from workspace',
        ]);
    }

    public function test_send_reply_clears_reply_text(): void
    {
        Queue::fake();

        $botUser = BotUser::create(['chat_id' => '1001', 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Something')
            ->call('sendReply')
            ->assertSet('replyText', '');
    }

    public function test_send_reply_requires_non_empty_text(): void
    {
        $botUser = BotUser::create(['chat_id' => '1002', 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', '')
            ->call('sendReply')
            ->assertHasErrors(['replyText' => 'required']);
    }

    public function test_send_reply_skipped_when_no_active_dialog(): void
    {
        Queue::fake();

        Livewire::test(ConversationPage::class)
            ->set('replyText', 'Orphaned reply')
            ->call('sendReply');

        $this->assertDatabaseMissing('messages', [
            'message_type' => 'outgoing',
            'text' => 'Orphaned reply',
        ]);
    }

    public function test_reply_form_hidden_in_telegram_group_mode(): void
    {
        config(['app.manager_interface' => 'telegram_group']);

        $botUser = BotUser::create(['chat_id' => '1003', 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id);

        $component->assertSet('activeBotUserId', $botUser->id);

        $this->assertFalse($component->instance()->shouldShowReplyForm());
    }

    // ── Quick replies ──────────────────────────────────────────────────────────

    public function test_insert_quick_reply_sets_reply_text(): void
    {
        Livewire::test(ConversationPage::class)
            ->call('insertQuickReply', 'Ожидайте, пожалуйста')
            ->assertSet('replyText', 'Ожидайте, пожалуйста');
    }

    // ── Media gallery ──────────────────────────────────────────────────────────

    public function test_get_image_attachments_returns_images_for_active_dialog(): void
    {
        $botUser = BotUser::create(['chat_id' => '2000', 'platform' => 'telegram']);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
        ]);

        MessageAttachment::create([
            'message_id' => $message->id,
            'file_id' => 'file_abc',
            'file_type' => 'photo',
        ]);

        MessageAttachment::create([
            'message_id' => $message->id,
            'file_id' => 'file_doc',
            'file_type' => 'document',
        ]);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id);

        $attachments = $component->instance()->getImageAttachments();

        $this->assertCount(1, $attachments);
        $this->assertEquals('photo', $attachments->first()->file_type);
    }

    public function test_get_image_attachments_returns_empty_without_active_dialog(): void
    {
        $component = Livewire::test(ConversationPage::class);

        $this->assertTrue($component->instance()->getImageAttachments()->isEmpty());
    }
}
