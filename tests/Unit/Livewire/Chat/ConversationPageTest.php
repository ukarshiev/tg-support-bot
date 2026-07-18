<?php

namespace Tests\Unit\Livewire\Chat;

use App\Jobs\TranslateMessageHistoryBatchJob;
use App\Jobs\TranslateMessageHistoryJob;
use App\Livewire\Chat\ConversationPage;
use App\Models\AiMessage;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageTranslation;
use App\Models\TranslationJob;
use App\Models\User;
use App\Modules\Telegram\Jobs\SendTelegramSimpleQueryJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        Cache::flush();
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

    // ── Dialog list pagination (infinite scroll) ────────────────────────────────

    private function seedChats(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            BotUser::create(['chat_id' => $i, 'platform' => 'telegram']);
        }
    }

    public function test_dialog_list_loads_only_the_first_page(): void
    {
        $this->seedChats(40);

        $component = Livewire::test(ConversationPage::class)
            ->assertSet('hasMoreDialogs', true);

        $this->assertCount(30, $component->get('dialogList'));
    }

    public function test_load_more_dialogs_grows_the_window(): void
    {
        $this->seedChats(40);

        $component = Livewire::test(ConversationPage::class)
            ->call('loadMoreDialogs')
            ->assertSet('hasMoreDialogs', false);

        $this->assertCount(40, $component->get('dialogList'));
    }

    public function test_small_dialog_list_reports_no_more(): void
    {
        $this->seedChats(5);

        $component = Livewire::test(ConversationPage::class)
            ->assertSet('hasMoreDialogs', false);

        $this->assertCount(5, $component->get('dialogList'));
    }

    public function test_changing_search_resets_the_dialog_window(): void
    {
        $this->seedChats(40);

        Livewire::test(ConversationPage::class)
            ->call('loadMoreDialogs')          // window grown to 60
            ->assertSet('dialogLimit', 60)
            ->set('search', '1')               // updatedSearch resets the window
            ->assertSet('dialogLimit', 30);
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

    public function test_language_selector_is_hidden_and_contact_summary_is_rendered_when_language_is_selected(): void
    {
        $botUser = BotUser::create([
            'chat_id' => '7001',
            'platform' => 'telegram',
            'display_name' => 'Barka Dark',
            'username' => 'Dark_Barka',
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
            'preferred_language_selected_at' => now(),
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => '/start',
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 2,
            'text' => 'Выберите язык / Choose your language:',
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 3,
            'text' => 'Welcome text',
        ]);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id);

        $html = $component->html();
        $threadStart = strpos($html, 'id="chat-thread"');
        $threadEnd = strpos($html, 'Pending AI drafts', $threadStart ?: 0);
        $threadHtml = $threadStart === false
            ? $html
            : substr($html, $threadStart, $threadEnd === false ? null : $threadEnd - $threadStart);

        $startPos = strpos($threadHtml, '/start');
        $contactPos = strpos($threadHtml, 'КОНТАКТНАЯ ИНФОРМАЦИЯ');
        $selectorPos = strpos($threadHtml, 'Выберите язык / Choose your language:');
        $welcomePos = strpos($threadHtml, 'Welcome text');

        $this->assertIsInt($startPos);
        $this->assertIsInt($contactPos);
        $this->assertFalse($selectorPos);
        $this->assertIsInt($welcomePos);
        $this->assertLessThan($contactPos, $startPos);
        $this->assertLessThan($contactPos, $welcomePos);
        $this->assertStringContainsString('Источник: telegram', $threadHtml);
        $this->assertStringContainsString('Выбранный язык: English', $threadHtml);
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

    // ── Dialog list ordering ────────────────────────────────────────────────────

    public function test_dialog_list_is_ordered_by_latest_message_descending(): void
    {
        $makeChatWithMessageAt = function (int $chatId, \Illuminate\Support\Carbon $at): BotUser {
            $user = BotUser::create(['chat_id' => $chatId, 'platform' => 'telegram']);
            $msg = Message::create([
                'bot_user_id' => $user->id,
                'platform' => 'telegram',
                'message_type' => 'incoming',
                'from_id' => 0,
                'to_id' => 0,
                'text' => 'm',
            ]);
            $msg->forceFill(['created_at' => $at])->save();

            return $user;
        };

        // Insert out of chronological order to prove the sort, not insertion order.
        $oldest = $makeChatWithMessageAt(101, now()->subMinutes(30));
        $newest = $makeChatWithMessageAt(102, now()->subMinute());
        $middle = $makeChatWithMessageAt(103, now()->subMinutes(10));

        $list = Livewire::test(ConversationPage::class)->get('dialogList');

        $this->assertSame(
            [$newest->id, $middle->id, $oldest->id],
            $list->pluck('id')->all()
        );
    }

    public function test_dialog_list_order_follows_message_date_not_insertion_order(): void
    {
        // Queue lag: the chat whose message row was inserted LAST (highest id) can
        // carry an OLDER message date. The list must follow the date, not the id.
        $userA = BotUser::create(['chat_id' => 201, 'platform' => 'telegram']);
        $userB = BotUser::create(['chat_id' => 202, 'platform' => 'telegram']);

        // A's message: inserted first (lower id) but newer date.
        $a = Message::create([
            'bot_user_id' => $userA->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'a',
        ]);
        $a->forceFill(['created_at' => now()->subMinute()])->save();

        // B's message: inserted later (higher id) but older date.
        $b = Message::create([
            'bot_user_id' => $userB->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'b',
        ]);
        $b->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $list = Livewire::test(ConversationPage::class)->get('dialogList');

        // A (newer message date) must be above B despite B having the higher id.
        $this->assertSame([$userA->id, $userB->id], $list->pluck('id')->all());
    }

    public function test_dialog_list_order_survives_a_roundtrip_without_reload(): void
    {
        // id order is the REVERSE of message-date order, so any fallback to
        // id ordering (e.g. lost on Livewire hydration) is detectable.
        $older = BotUser::create(['chat_id' => 301, 'platform' => 'telegram']); // lower id, older msg
        $newer = BotUser::create(['chat_id' => 302, 'platform' => 'telegram']); // higher id, newer msg

        $o = Message::create([
            'bot_user_id' => $older->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'o',
        ]);
        $o->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $n = Message::create([
            'bot_user_id' => $newer->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'n',
        ]);
        $n->forceFill(['created_at' => now()->subMinute()])->save();

        $component = Livewire::test(ConversationPage::class);

        // Correct on the initial render.
        $this->assertSame(
            [$newer->id, $older->id],
            $component->get('dialogList')->pluck('id')->all()
        );

        // A round-trip that does NOT call loadDialogList() must keep the order.
        $component->call('insertQuickReply', 'hello');

        $this->assertSame(
            [$newer->id, $older->id],
            $component->get('dialogList')->pluck('id')->all(),
            'Dialog order must survive a Livewire round-trip that does not reload the list.'
        );
    }

    // ── Lazy message loading (windowed thread) ──────────────────────────────────

    /**
     * Create $n text messages ("m1".."m$n") in order for the given user.
     */
    private function seedMessages(BotUser $botUser, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Message::create([
                'bot_user_id' => $botUser->id,
                'platform' => 'telegram',
                'message_type' => $i % 2 === 1 ? 'incoming' : 'outgoing',
                'from_id' => 1,
                'to_id' => 0,
                'text' => "m{$i}",
            ]);
        }
    }

    public function test_select_chat_loads_only_the_most_recent_page(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);
        $this->seedMessages($botUser, 60);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('hasMoreMessages', true);

        $msgs = $component->get('chatMessages');
        $this->assertCount(50, $msgs);
        $this->assertSame('m11', $msgs->first()->text);
        $this->assertSame('m60', $msgs->last()->text);
    }

    public function test_load_older_messages_prepends_previous_page(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);
        $this->seedMessages($botUser, 60);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('loadOlderMessages')
            ->assertSet('hasMoreMessages', false);

        $msgs = $component->get('chatMessages');
        $this->assertCount(60, $msgs);
        $this->assertSame('m1', $msgs->first()->text);
        $this->assertSame('m60', $msgs->last()->text);
    }

    public function test_small_thread_reports_no_more_messages(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);
        $this->seedMessages($botUser, 3);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('hasMoreMessages', false);

        $this->assertCount(3, $component->get('chatMessages'));
    }

    public function test_poll_appends_new_messages_without_resetting_loaded_history(): void
    {
        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);
        $this->seedMessages($botUser, 60);

        $component = Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)   // 50 loaded
            ->call('loadOlderMessages');          // full 60 loaded

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'fresh',
        ]);

        $component->call('pollUpdates');

        $msgs = $component->get('chatMessages');
        $this->assertCount(61, $msgs);
        $this->assertSame('fresh', $msgs->last()->text);
    }

    public function test_non_russian_chat_queues_visible_history_translation(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 11001,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => now(),
        ]);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Merhaba',
        ]);
        $selector = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'message_kind' => Message::KIND_LANGUAGE_SELECTOR,
            'from_id' => 0,
            'to_id' => 2,
            'text' => 'Choose language',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('chatTranslationLocale', 'tr')
            ->assertSet('chatHistoryTranslationActive', true)
            ->assertSee('🇹🇷 TR');

        $this->assertDatabaseHas('message_translations', [
            'message_id' => $message->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'direction' => 'client_to_operator',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'job_type' => TranslationJob::TYPE_MESSAGE_HISTORY,
            'subject_id' => $message->id,
            'status' => TranslationJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseMissing('message_translations', [
            'message_id' => $selector->id,
        ]);
        Queue::assertPushed(TranslateMessageHistoryBatchJob::class);
    }

    public function test_russian_chat_shows_flag_without_queuing_history_translation(): void
    {
        Queue::fake();

        $botUser = BotUser::create([
            'chat_id' => 11002,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
            'preferred_language_selected_at' => now(),
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Привет',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('chatTranslationLocale', 'ru')
            ->assertSet('chatHistoryTranslationActive', false)
            ->assertSee('RU');

        $this->assertDatabaseCount('message_translations', 0);
        Queue::assertNotPushed(TranslateMessageHistoryJob::class);
        Queue::assertNotPushed(TranslateMessageHistoryBatchJob::class);
    }

    public function test_chat_without_selected_language_shows_not_selected(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 11006,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
            'preferred_language_selected_at' => null,
            'chat_translation_locale' => null,
            'chat_translation_locale_selected_at' => null,
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Вы тут?',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('chatTranslationLocale', null)
            ->assertSet('chatHistoryTranslationActive', false)
            ->assertSee('Не выбран')
            ->assertDontSee('🇺🇸 EN ON');

        $this->assertDatabaseCount('message_translations', 0);
        Queue::assertNotPushed(TranslateMessageHistoryJob::class);
        Queue::assertNotPushed(TranslateMessageHistoryBatchJob::class);
    }

    public function test_manual_chat_language_change_is_saved_only_to_chat_context(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => false, 'show_on_start' => true, 'sort_order' => 3],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 11003,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
            'preferred_language_name' => 'Русский',
            'preferred_language_selected_at' => now(),
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Merhaba',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('setChatTranslationLocale', 'tr')
            ->assertSet('chatTranslationLocale', 'tr')
            ->assertSee('🇹🇷 TR')
            ->assertDontSee('🇺🇸 EN');

        $botUser->refresh();
        $this->assertSame('ru', $botUser->preferred_language_code);
        $this->assertSame('tr', $botUser->chat_translation_locale);
    }

    public function test_selecting_another_chat_resets_composer_and_uses_new_chat_language(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'es' => ['code' => 'es', 'name' => 'Español', 'native' => '🇪🇸 Español', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 3],
        ]);

        $turkish = BotUser::create([
            'chat_id' => 12001,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => now(),
        ]);
        $spanish = BotUser::create([
            'chat_id' => 12002,
            'platform' => 'telegram',
            'preferred_language_code' => 'es',
            'preferred_language_name' => 'Español',
            'preferred_language_selected_at' => now(),
        ]);

        Message::create([
            'bot_user_id' => $turkish->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Merhaba',
        ]);
        Message::create([
            'bot_user_id' => $spanish->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 2,
            'to_id' => 0,
            'text' => 'Hola',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $turkish->id)
            ->set('replyText', 'Черновик для турецкого клиента')
            ->call('selectChat', $spanish->id)
            ->assertSet('replyText', '')
            ->assertSet('replyTranslatedText', null)
            ->assertSet('replyTranslationStatus', 'empty')
            ->assertSet('chatTranslationLocale', 'es')
            ->assertSee('🇪🇸 ES')
            ->assertDontSee('Черновик для турецкого клиента')
            ->assertDontSee('🇹🇷 TR ON');
    }

    public function test_preferred_language_wins_over_stale_chat_translation_locale(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'es' => ['code' => 'es', 'name' => 'Español', 'native' => '🇪🇸 Español', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 3],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 12003,
            'platform' => 'telegram',
            'preferred_language_code' => 'es',
            'preferred_language_name' => 'Español',
            'preferred_language_selected_at' => now(),
            'chat_translation_locale' => 'tr',
            'chat_translation_locale_selected_at' => now()->subHour(),
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 3,
            'to_id' => 0,
            'text' => 'Hola',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('chatTranslationLocale', 'es')
            ->assertSee('🇪🇸 ES')
            ->assertDontSee('🇹🇷 TR ON');
    }

    public function test_outgoing_history_prefers_restored_operator_translation_over_client_text(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 12004,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => now(),
        ]);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'outgoing',
            'from_id' => 0,
            'to_id' => 12004,
            'text' => 'Siparişiniz hazırlanıyor.',
        ]);

        MessageTranslation::create([
            'message_id' => $message->id,
            'source_locale' => 'ru',
            'target_locale' => 'tr',
            'source_text' => 'Siparişiniz hazırlanıyor.',
            'translated_text' => 'Siparişiniz hazırlanıyor.',
            'direction' => 'operator_to_client',
            'status' => 'ready',
            'source' => 'auto',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Siparişiniz hazırlanıyor.'),
            'translated_at' => now(),
        ]);
        MessageTranslation::create([
            'message_id' => $message->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_text' => 'Siparişiniz hazırlanıyor.',
            'translated_text' => 'Ваш заказ готовится.',
            'direction' => 'system_to_operator',
            'status' => 'ready',
            'source' => 'auto',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Siparişiniz hazırlanıyor.'),
            'translated_at' => now(),
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSee('Ваш заказ готовится.')
            ->assertSee('Siparişiniz hazırlanıyor.');
    }

    public function test_pending_ai_draft_restores_russian_operator_layer_when_source_is_client_language(): void
    {
        Queue::fake();

        app(SettingsService::class)->set('translation.provider_order', ['fake']);
        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
        ]);

        $botUser = BotUser::create([
            'chat_id' => 12005,
            'platform' => 'telegram',
            'preferred_language_code' => 'en',
            'preferred_language_name' => 'English',
        ]);

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 12005,
            'to_id' => 0,
            'text' => 'Thanks',
        ]);

        AiMessage::create([
            'bot_user_id' => $botUser->id,
            'status' => AiMessage::STATUS_PENDING,
            'text_ai' => "You're welcome! I'm glad to help.",
            'text_source' => "You're welcome! I'm glad to help.",
            'text_translated' => "You're welcome! I'm glad to help.",
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'translation_status' => 'ready',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSee('RU')
            ->assertSee('Выбранный язык')
            ->assertSee("[ru] You're welcome! I'm glad to help.");
    }

    public function test_retry_message_translation_requeues_only_one_failed_message(): void
    {
        Queue::fake();

        $botUser = BotUser::create([
            'chat_id' => 11004,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => now(),
        ]);

        $failed = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Hata',
        ]);
        $other = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Tamam',
        ]);

        MessageTranslation::create([
            'message_id' => $failed->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_text' => 'Hata',
            'direction' => 'client_to_operator',
            'status' => 'failed',
            'source' => 'auto',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Hata'),
            'error_message' => 'Провайдер недоступен',
        ]);
        MessageTranslation::create([
            'message_id' => $other->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_text' => 'Tamam',
            'translated_text' => 'Хорошо',
            'direction' => 'client_to_operator',
            'status' => 'ready',
            'source' => 'auto',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Tamam'),
            'translated_at' => now(),
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('retryMessageTranslation', $failed->id);

        $this->assertDatabaseHas('message_translations', [
            'message_id' => $failed->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('message_translations', [
            'message_id' => $other->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(TranslateMessageHistoryJob::class, 1);
        Queue::assertNotPushed(TranslateMessageHistoryBatchJob::class);
    }

    public function test_failed_message_translation_waits_for_manual_retry(): void
    {
        Queue::fake();

        $botUser = BotUser::create([
            'chat_id' => 11007,
            'platform' => 'telegram',
            'preferred_language_code' => 'tr',
            'preferred_language_name' => 'Türkçe',
            'preferred_language_selected_at' => now(),
        ]);

        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 1,
            'to_id' => 0,
            'text' => 'Hata',
        ]);

        MessageTranslation::create([
            'message_id' => $message->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_text' => 'Hata',
            'direction' => 'client_to_operator',
            'status' => 'failed',
            'source' => 'auto',
            'source_hash' => \App\Modules\Translation\Services\TranslationService::sourceHash('Hata'),
            'error_message' => 'Провайдер недоступен',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSee('Не удалось перевести');

        $this->assertDatabaseHas('message_translations', [
            'message_id' => $message->id,
            'status' => 'failed',
        ]);
        Queue::assertNotPushed(TranslateMessageHistoryJob::class);
        Queue::assertNotPushed(TranslateMessageHistoryBatchJob::class);
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

        $botUser = BotUser::create([
            'chat_id' => 100,
            'platform' => 'telegram',
            'preferred_language_code' => 'ru',
            'preferred_language_selected_at' => now(),
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Hello!')
            ->call('sendReply')
            ->assertHasNoErrors()
            ->assertDispatched('admin-toast', message: 'Сообщение отправлено');

        $this->assertDatabaseHas('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Hello!',
        ]);

        Queue::assertPushed(SendTelegramSimpleQueryJob::class);
    }

    public function test_foreign_reply_is_blocked_until_translation_is_ready(): void
    {
        Queue::fake();

        $botUser = BotUser::create([
            'chat_id' => 101,
            'platform' => 'telegram',
            'preferred_language_code' => 'fr',
            'preferred_language_selected_at' => now(),
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->set('replyText', 'Русский текст')
            ->set('replyTranslationStatus', 'error')
            ->set('replyTranslatedText', null)
            ->call('sendReply')
            ->assertDispatched('admin-toast', message: 'Перевод ещё не готов. Текст не отправлен.', type: 'error');

        $this->assertDatabaseMissing('messages', [
            'bot_user_id' => $botUser->id,
            'message_type' => 'outgoing',
            'text' => 'Русский текст',
        ]);
        Queue::assertNotPushed(SendTelegramSimpleQueryJob::class);
    }

    // ── Polling interval ───────────────────────────────────────────────────────

    public function test_polling_interval_is_thirty_seconds_fallback(): void
    {
        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertEquals('30s', $instance->getPollingInterval());
    }

    // ── shouldShowReplyForm ────────────────────────────────────────────────────

    public function test_should_show_reply_form_returns_true(): void
    {
        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertTrue($instance->shouldShowReplyForm());
    }

    // ── Desktop notifications ───────────────────────────────────────────────────

    private function makeIncoming(BotUser $user, string $text = 'hi'): Message
    {
        return Message::create([
            'bot_user_id' => $user->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => $text,
        ]);
    }

    public function test_poll_dispatches_notification_for_new_incoming_message(): void
    {
        $user = BotUser::create(['chat_id' => 7001, 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class);
        $this->makeIncoming($user, 'привет');

        $component->call('pollUpdates')
            ->assertDispatched('new-incoming-messages');
    }

    public function test_poll_does_not_notify_for_preexisting_messages(): void
    {
        $user = BotUser::create(['chat_id' => 7002, 'platform' => 'telegram']);
        $this->makeIncoming($user); // exists before mount → part of the baseline

        Livewire::test(ConversationPage::class)
            ->call('pollUpdates')
            ->assertNotDispatched('new-incoming-messages');
    }

    public function test_poll_does_not_notify_for_the_active_dialog(): void
    {
        $user = BotUser::create(['chat_id' => 7003, 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class)->call('selectChat', $user->id);
        $this->makeIncoming($user, 'в открытом чате');

        $component->call('pollUpdates')
            ->assertNotDispatched('new-incoming-messages');
    }

    public function test_poll_does_not_notify_for_outgoing_messages(): void
    {
        $user = BotUser::create(['chat_id' => 7004, 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class);
        Message::create([
            'bot_user_id' => $user->id, 'platform' => 'telegram', 'message_type' => 'outgoing',
            'from_id' => 0, 'to_id' => 0, 'text' => 'ответ',
        ]);

        $component->call('pollUpdates')
            ->assertNotDispatched('new-incoming-messages');
    }

    public function test_poll_notifies_only_once_per_message(): void
    {
        $user = BotUser::create(['chat_id' => 7005, 'platform' => 'telegram']);

        $component = Livewire::test(ConversationPage::class);
        $this->makeIncoming($user);

        $component->call('pollUpdates')->assertDispatched('new-incoming-messages');
        // Watermark advanced — the same message must not notify again.
        $component->call('pollUpdates')->assertNotDispatched('new-incoming-messages');
    }

    // ── deleteChat ─────────────────────────────────────────────────────────────

    public function test_delete_chat_removes_dialog_with_messages_and_clears_active(): void
    {
        $botUser = BotUser::create(['chat_id' => 6001, 'platform' => 'telegram']);
        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'hello',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->assertSet('activeBotUserId', $botUser->id)
            ->call('deleteChat')
            ->assertSet('activeBotUserId', null)
            ->assertSet('activeBotUser', null);

        $this->assertDatabaseMissing('bot_users', ['id' => $botUser->id]);
        $this->assertSame(0, Message::where('bot_user_id', $botUser->id)->count());
    }

    public function test_delete_chat_is_noop_without_active_dialog(): void
    {
        $botUser = BotUser::create(['chat_id' => 6002, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('deleteChat')
            ->assertSet('activeBotUserId', null);

        // Nothing deleted when no dialog is open.
        $this->assertDatabaseHas('bot_users', ['id' => $botUser->id]);
    }

    // ── clearHistory ───────────────────────────────────────────────────────────

    public function test_clear_history_removes_messages_but_keeps_active_chat(): void
    {
        $botUser = BotUser::create(['chat_id' => 6101, 'platform' => 'telegram']);
        Message::create([
            'bot_user_id' => $botUser->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'one',
        ]);
        Message::create([
            'bot_user_id' => $botUser->id, 'platform' => 'telegram', 'message_type' => 'outgoing',
            'from_id' => 0, 'to_id' => 0, 'text' => 'two',
        ]);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('clearHistory')
            ->assertSet('activeBotUserId', $botUser->id)
            ->assertCount('chatMessages', 0);

        $this->assertDatabaseHas('bot_users', ['id' => $botUser->id]);
        $this->assertSame(0, Message::where('bot_user_id', $botUser->id)->count());
    }

    public function test_clear_history_is_noop_without_active_dialog(): void
    {
        $botUser = BotUser::create(['chat_id' => 6102, 'platform' => 'telegram']);
        Message::create([
            'bot_user_id' => $botUser->id, 'platform' => 'telegram', 'message_type' => 'incoming',
            'from_id' => 0, 'to_id' => 0, 'text' => 'keep',
        ]);

        Livewire::test(ConversationPage::class)->call('clearHistory');

        $this->assertSame(1, Message::where('bot_user_id', $botUser->id)->count());
    }

    // ── profileUrl ─────────────────────────────────────────────────────────────

    public function test_profile_url_is_null_without_active_dialog(): void
    {
        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertNull($instance->profileUrl());
    }

    public function test_profile_url_for_vk_is_a_web_link(): void
    {
        $user = BotUser::create(['chat_id' => 12345, 'platform' => 'vk']);

        $instance = Livewire::test(ConversationPage::class)->call('selectChat', $user->id)->instance();

        $this->assertSame('https://vk.com/id12345', $instance->profileUrl());
    }

    public function test_profile_url_is_null_for_telegram(): void
    {
        // A numeric Telegram chat_id cannot be turned into a working profile link
        // (no stored @username), so no link is offered.
        $user = BotUser::create(['chat_id' => 67890, 'platform' => 'telegram']);

        $instance = Livewire::test(ConversationPage::class)->call('selectChat', $user->id)->instance();

        $this->assertNull($instance->profileUrl());
    }

    public function test_profile_url_is_null_for_unsupported_platform(): void
    {
        $user = BotUser::create(['chat_id' => 555, 'platform' => 'max']);

        $instance = Livewire::test(ConversationPage::class)->call('selectChat', $user->id)->instance();

        $this->assertNull($instance->profileUrl());
    }

    // ── insertQuickReply ──────────────────────────────────────────────────────

    public function test_insert_quick_reply_sets_reply_text(): void
    {
        Livewire::test(ConversationPage::class)
            ->call('insertQuickReply', 'Ожидайте, пожалуйста')
            ->assertSet('replyText', 'Ожидайте, пожалуйста');
    }

    // ── hasUnread ──────────────────────────────────────────────────────────────

    private function botUserWithLastMessage(string $type, array $attrs = []): BotUser
    {
        $botUser = BotUser::create(array_merge(['chat_id' => 5000, 'platform' => 'telegram'], $attrs));

        Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => $type,
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'msg',
        ]);

        return $botUser->fresh(['lastMessage']);
    }

    public function test_has_unread_true_for_open_dialog_with_incoming_last_message(): void
    {
        $user = $this->botUserWithLastMessage('incoming');

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertTrue($instance->hasUnread($user));
    }

    public function test_has_unread_false_when_last_message_outgoing(): void
    {
        $user = $this->botUserWithLastMessage('outgoing');

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->hasUnread($user));
    }

    public function test_has_unread_false_for_closed_dialog(): void
    {
        $user = $this->botUserWithLastMessage('incoming', ['is_closed' => true, 'closed_at' => now()]);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->hasUnread($user));
    }

    public function test_has_unread_false_for_banned_dialog(): void
    {
        $user = $this->botUserWithLastMessage('incoming', ['is_banned' => true, 'banned_at' => now()]);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->hasUnread($user));
    }

    public function test_has_unread_false_for_active_dialog(): void
    {
        $user = $this->botUserWithLastMessage('incoming');

        $component = Livewire::test(ConversationPage::class)->call('selectChat', $user->id);

        $this->assertFalse($component->instance()->hasUnread($user));
    }

    public function test_has_unread_false_when_read_after_last_message(): void
    {
        $user = $this->botUserWithLastMessage('incoming', ['manager_last_read_at' => now()->addHour()]);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->hasUnread($user));
    }

    public function test_has_unread_true_when_message_arrived_after_read(): void
    {
        $user = $this->botUserWithLastMessage('incoming', ['manager_last_read_at' => now()->subHour()]);

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertTrue($instance->hasUnread($user));
    }

    public function test_selecting_dialog_persists_read_timestamp(): void
    {
        $user = $this->botUserWithLastMessage('incoming');

        Livewire::test(ConversationPage::class)->call('selectChat', $user->id);

        // Persisted: a fresh, non-active load is no longer flagged unread.
        $reloaded = $user->fresh(['lastMessage']);
        $this->assertNotNull($reloaded->manager_last_read_at);

        $freshComponent = Livewire::test(ConversationPage::class)->instance();
        $this->assertFalse($freshComponent->hasUnread($reloaded));
    }

    public function test_opening_dialog_marks_all_messages_read_despite_queue_lag(): void
    {
        // Messages are persisted by queued jobs, so a message that arrived before
        // the manager opened the dialog can land with a created_at slightly ahead
        // of "now" (the job ran after the click). Such a message must still be
        // treated as read once the dialog is opened.
        $user = BotUser::create(['chat_id' => 5002, 'platform' => 'telegram']);

        $lagged = Message::create([
            'bot_user_id' => $user->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'queued',
        ]);
        $lagged->forceFill(['created_at' => now()->addMinute()])->save();

        Livewire::test(ConversationPage::class)->call('selectChat', $user->id);

        $reloaded = $user->fresh(['lastMessage']);
        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertFalse($instance->hasUnread($reloaded));
        $this->assertSame(0, $instance->unreadCount($reloaded));
    }

    // ── unreadCount ────────────────────────────────────────────────────────────

    public function test_unread_count_returns_number_of_incoming_messages(): void
    {
        $user = $this->botUserWithLastMessage('incoming');

        // Two more incoming messages (3 total) for the same dialog.
        foreach (range(1, 2) as $i) {
            Message::create([
                'bot_user_id' => $user->id,
                'platform' => 'telegram',
                'message_type' => 'incoming',
                'from_id' => 0,
                'to_id' => 0,
                'text' => 'msg ' . $i,
            ]);
        }

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertSame(3, $instance->unreadCount($user->fresh(['lastMessage'])));
    }

    public function test_unread_count_is_zero_when_dialog_not_unread(): void
    {
        $user = $this->botUserWithLastMessage('outgoing');

        $instance = Livewire::test(ConversationPage::class)->instance();

        $this->assertSame(0, $instance->unreadCount($user));
    }

    public function test_unread_count_only_counts_messages_after_read_timestamp(): void
    {
        $user = BotUser::create([
            'chat_id' => 5001,
            'platform' => 'telegram',
            'manager_last_read_at' => now()->subMinutes(30),
        ]);

        // Older incoming message, read earlier (before the read timestamp).
        // created_at is not fillable, so set it explicitly after creation.
        $old = Message::create([
            'bot_user_id' => $user->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'old',
        ]);
        $old->forceFill(['created_at' => now()->subHour()])->save();

        // Newer incoming message (the latest one) — arrived after the read mark.
        Message::create([
            'bot_user_id' => $user->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 0,
            'to_id' => 0,
            'text' => 'new',
        ]);

        $instance = Livewire::test(ConversationPage::class)->instance();

        // Only the post-read message counts; the older one is excluded.
        $this->assertSame(1, $instance->unreadCount($user->fresh(['lastMessage'])));
    }

    // ── getMediaAttachments ──────────────────────────────────────────────────

    public function test_get_media_attachments_returns_empty_without_active_dialog(): void
    {
        $component = Livewire::test(ConversationPage::class);

        $this->assertTrue($component->instance()->getMediaAttachments()->isEmpty());
    }

    // ── deleteChat (admin-only) ──────────────────────────────────────────────

    public function test_is_admin_true_for_admin_user(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertTrue(Livewire::test(ConversationPage::class)->instance()->isAdmin());
    }

    public function test_is_admin_false_for_manager_user(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(Livewire::test(ConversationPage::class)->instance()->isAdmin());
    }

    public function test_admin_can_delete_chat(): void
    {
        $this->actingAs(User::factory()->create());

        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('deleteChat')
            ->assertSet('activeBotUser', null);

        $this->assertDatabaseMissing('bot_users', ['id' => $botUser->id]);
    }

    public function test_manager_cannot_delete_chat(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $botUser = BotUser::create(['chat_id' => 1, 'platform' => 'telegram']);

        Livewire::test(ConversationPage::class)
            ->call('selectChat', $botUser->id)
            ->call('deleteChat')
            ->assertStatus(403);

        $this->assertDatabaseHas('bot_users', ['id' => $botUser->id]);
    }
}
