<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\AiKnowledgePage;
use App\Models\AiKnowledgeItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->set('statusFilter', 'active')
            ->assertSee('Активный блок')
            ->assertDontSee('Выключенный блок')
            ->set('statusFilter', 'inactive')
            ->assertSee('Выключенный блок')
            ->assertDontSee('Активный блок');
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
}
