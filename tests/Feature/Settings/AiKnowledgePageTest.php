<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\AiKnowledgePage;
use App\Models\AiKnowledgeItem;
use App\Models\AiSupportKnowledgeChunk;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class AiKnowledgePageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_route_is_registered_and_admin_can_render_page(): void
    {
        $this->actingAdmin();

        $this->assertTrue(Route::has('admin.settings.ai.knowledge'));

        $this->get(route('admin.settings.ai.knowledge'))
            ->assertOk()
            ->assertSee('База знаний AI');
    }

    public function test_page_has_separate_tabs_for_support_blocks_and_moderation(): void
    {
        $this->actingAdmin();

        Livewire::test(AiKnowledgePage::class)
            ->assertSet('activeTab', 'support')
            ->assertSee('Support-диалоги')
            ->assertSee('Блоки знаний')
            ->assertSee('AI-модератор')
            ->assertSee('Старые обращения используются как похожие примеры')
            ->call('setActiveTab', 'moderation')
            ->assertSet('activeTab', 'moderation')
            ->assertSee('Правила, по которым DeepSeek')
            ->assertSee('KAR-295 / v1 / 03.07.2026')
            ->call('setActiveTab', 'blocks')
            ->assertSet('activeTab', 'blocks')
            ->assertSee('Добавить блок');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.settings.ai.knowledge'))
            ->assertRedirectContains('/admin/login');
    }

    public function test_page_displays_knowledge_items(): void
    {
        $this->actingAdmin();

        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'Цена BroSpace: 500 ₽.',
            'keywords' => ['brospace', 'цена'],
            'priority' => 10,
            'is_active' => true,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->set('activeTab', 'blocks')
            ->assertSee('BroSpace')
            ->assertSee('product-brospace')
            ->assertSee('brospace');
    }

    public function test_search_finds_item_by_title_slug_content_and_keyword(): void
    {
        $this->actingAdmin();

        AiKnowledgeItem::create([
            'slug' => 'product-brospace',
            'title' => 'BroSpace',
            'content' => 'Цена BroSpace: 500 ₽.',
            'keywords' => ['brospace'],
            'priority' => 10,
            'is_active' => true,
        ]);
        AiKnowledgeItem::create([
            'slug' => 'faq-navigation',
            'title' => 'Навигация',
            'content' => 'Как искать по ID поста.',
            'keywords' => ['хештеги'],
            'priority' => 20,
            'is_active' => true,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->set('activeTab', 'blocks')
            ->set('search', 'brospace')
            ->assertSee('BroSpace')
            ->assertDontSee('Навигация')
            ->set('search', 'хештеги')
            ->assertSee('Навигация')
            ->assertDontSee('BroSpace');
    }

    public function test_status_filter_works(): void
    {
        $this->actingAdmin();

        AiKnowledgeItem::create([
            'slug' => 'active-item',
            'title' => 'Активный блок',
            'content' => 'Текст',
            'is_active' => true,
        ]);
        AiKnowledgeItem::create([
            'slug' => 'inactive-item',
            'title' => 'Выключенный блок',
            'content' => 'Текст',
            'is_active' => false,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->set('activeTab', 'blocks')
            ->set('statusFilter', 'active')
            ->assertSee('Активный блок')
            ->assertDontSee('Выключенный блок')
            ->set('statusFilter', 'inactive')
            ->assertSee('Выключенный блок')
            ->assertDontSee('Активный блок');
    }

    public function test_support_tab_filters_searches_and_opens_drawer(): void
    {
        $this->actingAdmin();

        $active = AiSupportKnowledgeChunk::create([
            'source_hash' => 'active-case',
            'question' => 'Сколько стоит Elite?',
            'answer' => 'Elite стоит 2000 ₽.',
            'keywords' => ['elite'],
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
            'first_message_at' => now()->subDay(),
            'last_message_at' => now(),
        ]);
        AiSupportKnowledgeChunk::create([
            'source_hash' => 'review-case',
            'question' => 'Тестовый мусор?',
            'answer' => 'Нужно проверить.',
            'keywords' => ['review'],
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_REVIEW,
            'moderation_reason' => 'Возможный дубль',
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->assertSet('activeTab', 'support')
            ->assertSee('Сколько стоит Elite?')
            ->assertSee('Тестовый мусор?')
            ->set('supportStatusFilter', 'active')
            ->assertSee('Сколько стоит Elite?')
            ->assertDontSee('Тестовый мусор?')
            ->set('supportStatusFilter', 'all')
            ->set('supportSearch', 'дубль')
            ->assertSee('Тестовый мусор?')
            ->assertDontSee('Сколько стоит Elite?')
            ->call('openSupportChunk', $active->id)
            ->assertSet('supportDrawerOpen', true)
            ->assertSee('Карточка support-кейса')
            ->assertSee('Elite стоит 2000 ₽.');
    }

    public function test_support_status_changes_sync_ai_visibility(): void
    {
        $this->actingAdmin();

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'status-case',
            'question' => 'Вопрос',
            'answer' => 'Ответ',
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('setSupportChunkStatus', $chunk->id, AiSupportKnowledgeChunk::STATUS_REVIEW)
            ->assertSet('successMessage', 'Статус support-кейса: Нужно проверить.');

        $chunk->refresh();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_REVIEW, $chunk->status);
        $this->assertFalse($chunk->is_active);

        Livewire::test(AiKnowledgePage::class)
            ->call('setSupportChunkStatus', $chunk->id, AiSupportKnowledgeChunk::STATUS_ACTIVE);

        $chunk->refresh();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_ACTIVE, $chunk->status);
        $this->assertTrue($chunk->is_active);
    }

    public function test_support_delete_removes_chunk_physically(): void
    {
        $this->actingAdmin();

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'delete-support-case',
            'question' => 'Удалить?',
            'answer' => 'Да',
            'is_active' => false,
            'status' => AiSupportKnowledgeChunk::STATUS_DISABLED,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('openSupportChunk', $chunk->id)
            ->call('deleteSupportChunk', $chunk->id)
            ->assertSet('supportDrawerOpen', false)
            ->assertSet('successMessage', 'Support-кейс удалён.');

        $this->assertDatabaseMissing('ai_support_knowledge_chunks', ['id' => $chunk->id]);
    }

    public function test_fill_ai_modal_imports_current_dialogs_and_runs_moderation(): void
    {
        $this->actingAdmin();
        $settings = app(SettingsService::class);
        $settings->set('ai.support_moderator_provider', 'deepseek');
        $settings->set('ai.support_moderator_model', 'deepseek-chat');
        $settings->set('ai.deepseek_client_secret', 'test-secret');
        $settings->set('ai.deepseek_base_url', 'https://api.deepseek.test/v1');

        Http::fake([
            'https://api.deepseek.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'status' => 'active',
                            'quality_score' => 0.95,
                            'reason' => 'Хороший кейс.',
                            'risks' => [],
                            'duplicate_group_key' => null,
                            'recommended_action' => 'activate',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $botUser = BotUser::create([
            'chat_id' => 100500,
            'platform' => 'telegram',
        ]);
        $this->createMessage($botUser->id, 'incoming', 'How much Elite?');
        $this->createMessage($botUser->id, 'outgoing', 'Elite costs 2000 ₽.');

        Livewire::test(AiKnowledgePage::class)
            ->call('openFillAiModal')
            ->assertSet('fillAiModalOpen', true)
            ->assertSee('Пополнить базу AI')
            ->assertSee('Кандидаты')
            ->call('fillAiFromCurrentDialogs')
            ->assertSet('fillAiModalOpen', false)
            ->assertSet('supportStatusFilter', 'review')
            ->assertSee('База AI пополнена: новых 1 из 1, проверено AI 1, обновлено 1, ошибок 0.');

        $chunk = AiSupportKnowledgeChunk::firstOrFail();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_ACTIVE, $chunk->status);
        $this->assertTrue($chunk->is_active);
        $this->assertSame('Хороший кейс.', $chunk->moderation_reason);
    }

    public function test_archive_import_modal_saves_path_imports_and_moderates_new_cases(): void
    {
        $this->actingAdmin();
        $settings = app(SettingsService::class);
        $settings->set('ai.support_moderator_provider', 'deepseek');
        $settings->set('ai.support_moderator_model', 'deepseek-chat');
        $settings->set('ai.deepseek_client_secret', 'test-secret');
        $settings->set('ai.deepseek_base_url', 'https://api.deepseek.test/v1');

        Http::fake([
            'https://api.deepseek.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'status' => 'review',
                            'quality_score' => 0.75,
                            'reason' => 'Нужно проверить цену.',
                            'risks' => ['possible_outdated_price'],
                            'duplicate_group_key' => 'elite-price',
                            'recommended_action' => 'review',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $directory = $this->makeArchive([
            $this->messageHtml('message1', '⁉️Relaxa.Club Support Bot', '05.02.2026 08:14:52 UTC+03:00', 'Elite price?'),
            $this->messageHtml('message2', 'Ne0soul', '05.02.2026 10:08:10 UTC+03:00', 'Elite costs 2000 ₽.'),
        ]);
        $settings->set('ai.support_archive_path', $directory);

        Livewire::test(AiKnowledgePage::class)
            ->call('openArchiveImportModal')
            ->assertSet('archiveImportModalOpen', true)
            ->assertSee('Пополнить базу AI из архива')
            ->assertSee('Кандидаты')
            ->call('fillAiFromArchive')
            ->assertSet('archiveImportModalOpen', false)
            ->assertSee('Архив добавлен в базу AI: новых 1 из 1, проверено AI 1, обновлено 1, ошибок 0.');

        $this->assertSame($directory, (string) app(SettingsService::class)->get('ai.support_archive_path'));

        $chunk = AiSupportKnowledgeChunk::firstOrFail();
        $this->assertSame(AiSupportKnowledgeChunk::STATUS_REVIEW, $chunk->status);
        $this->assertFalse($chunk->is_active);
        $this->assertSame('elite-price', $chunk->duplicate_group_key);
    }

    public function test_create_saves_item_and_parses_keywords(): void
    {
        $this->actingAdmin();

        Livewire::test(AiKnowledgePage::class)
            ->call('openCreate')
            ->set('form.title', 'Новый блок')
            ->set('form.slug', 'new-block')
            ->set('form.content', 'Полезный текст')
            ->set('form.keywords', 'one, two, one,  три ')
            ->set('form.priority', 12)
            ->set('form.is_active', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Блок знаний создан.');

        $item = AiKnowledgeItem::where('slug', 'new-block')->firstOrFail();
        $this->assertSame('Новый блок', $item->title);
        $this->assertSame(['one', 'two', 'три'], $item->keywords);
        $this->assertSame(12, $item->priority);
        $this->assertTrue($item->is_active);
    }

    public function test_edit_updates_item(): void
    {
        $this->actingAdmin();

        $item = AiKnowledgeItem::create([
            'slug' => 'old-slug',
            'title' => 'Старое название',
            'content' => 'Старый текст',
            'keywords' => ['old'],
            'priority' => 100,
            'is_active' => true,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('openEdit', $item->id)
            ->set('form.title', 'Новое название')
            ->set('form.slug', 'new-slug')
            ->set('form.content', 'Новый текст')
            ->set('form.keywords', 'new, keyword')
            ->set('form.priority', 5)
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Блок знаний обновлён.');

        $item->refresh();
        $this->assertSame('new-slug', $item->slug);
        $this->assertSame('Новое название', $item->title);
        $this->assertSame(['new', 'keyword'], $item->keywords);
        $this->assertFalse($item->is_active);
    }

    public function test_toggle_active_changes_item_status(): void
    {
        $this->actingAdmin();

        $item = AiKnowledgeItem::create([
            'slug' => 'toggle-item',
            'title' => 'Toggle',
            'content' => 'Текст',
            'is_active' => true,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('toggleActive', $item->id)
            ->assertSet('successMessage', 'Блок выключен.');

        $this->assertFalse($item->fresh()->is_active);
    }

    public function test_delete_removes_item(): void
    {
        $this->actingAdmin();

        $item = AiKnowledgeItem::create([
            'slug' => 'delete-item',
            'title' => 'Delete',
            'content' => 'Текст',
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('deleteItem', $item->id)
            ->assertSet('successMessage', 'Блок знаний удалён.');

        $this->assertDatabaseMissing('ai_knowledge_items', ['id' => $item->id]);
    }

    public function test_slug_must_be_unique(): void
    {
        $this->actingAdmin();

        AiKnowledgeItem::create([
            'slug' => 'duplicate',
            'title' => 'Первый',
            'content' => 'Текст',
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('openCreate')
            ->set('form.title', 'Второй')
            ->set('form.slug', 'duplicate')
            ->set('form.content', 'Текст')
            ->call('save')
            ->assertHasErrors(['form.slug']);
    }

    public function test_slug_allows_only_safe_characters(): void
    {
        $this->actingAdmin();

        Livewire::test(AiKnowledgePage::class)
            ->call('openCreate')
            ->set('form.title', 'Блок')
            ->set('form.slug', 'плохой slug')
            ->set('form.content', 'Текст')
            ->call('save')
            ->assertHasErrors(['form.slug']);
    }

    public function test_support_drawer_saves_ru_canonical_and_ai_instruction(): void
    {
        $this->actingAdmin();

        $chunk = AiSupportKnowledgeChunk::create([
            'source_hash' => 'support-ru-edit-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('openSupportChunk', $chunk->id)
            ->assertSee('Что увидит AI')
            ->set('supportForm.question_ru', 'Сколько стоит Elite?')
            ->set('supportForm.answer_ru', 'Elite стоит 2000 ₽.')
            ->set('supportForm.ai_instruction', 'Не обещай скидку без подтверждения.')
            ->call('saveSupportCanonicalFields')
            ->assertSet('successMessage', 'RU canonical и инструкция AI сохранены.');

        $chunk->refresh();
        $this->assertSame('Сколько стоит Elite?', $chunk->question_ru);
        $this->assertSame('Elite стоит 2000 ₽.', $chunk->answer_ru);
        $this->assertSame('Не обещай скидку без подтверждения.', $chunk->ai_instruction);
        $this->assertTrue($chunk->question_ru_manually_edited);
        $this->assertSame(AiSupportKnowledgeChunk::TRANSLATION_MANUAL_EDITED, $chunk->question_translation_status);
    }

    public function test_support_translate_all_queues_translation_jobs(): void
    {
        $this->actingAdmin();
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        AiSupportKnowledgeChunk::create([
            'source_hash' => 'support-queue-case',
            'question' => 'How much Elite?',
            'answer' => 'Elite costs 2000 ₽.',
            'question_original' => 'How much Elite?',
            'answer_original' => 'Elite costs 2000 ₽.',
            'is_active' => true,
            'status' => AiSupportKnowledgeChunk::STATUS_ACTIVE,
        ]);

        Livewire::test(AiKnowledgePage::class)
            ->call('translateAllSupportChunks')
            ->assertSee('Массовый RU canonical поставлен в очередь: 1 кейсов.');

        $this->assertDatabaseHas('translation_jobs', [
            'job_type' => \App\Models\TranslationJob::TYPE_SUPPORT_CASE,
            'subject_label' => 'Support-кейс #1',
            'status' => \App\Models\TranslationJob::STATUS_DONE,
        ]);
    }

    private function createMessage(int $botUserId, string $type, string $text): Message
    {
        return Message::create([
            'bot_user_id' => $botUserId,
            'platform' => 'telegram',
            'message_type' => $type,
            'from_id' => 0,
            'to_id' => 0,
            'text' => $text,
        ]);
    }

    /**
     * @param array<int, string> $messages
     */
    private function makeArchive(array $messages): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ai-knowledge-archive-' . uniqid();
        File::makeDirectory($directory, 0755, true);
        File::put($directory . DIRECTORY_SEPARATOR . 'messages.html', '<html><body>' . implode("\n", $messages) . '</body></html>');

        return $directory;
    }

    private function messageHtml(string $id, string $sender, string $date, string $text): string
    {
        return <<<HTML
<div class="message default clearfix" id="{$id}">
  <div class="body">
    <div class="pull_right date details" title="{$date}">10:00</div>
    <div class="from_name">{$sender}</div>
    <div class="text">{$text}</div>
  </div>
</div>
HTML;
    }
}
