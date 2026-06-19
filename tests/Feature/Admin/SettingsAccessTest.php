<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    // ── Route access ──────────────────────────────────────────────────────────

    public function test_manager_can_open_general_settings(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.settings.general'))
            ->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminOnlySettingsRoutes(): array
    {
        return [
            'integrations' => ['admin.settings.integrations'],
            'ai' => ['admin.settings.ai'],
            'api-webhooks' => ['admin.settings.api-webhooks'],
            'team' => ['admin.settings.team'],
            'auto-replies' => ['admin.settings.auto-replies'],
        ];
    }

    /**
     * @dataProvider adminOnlySettingsRoutes
     */
    public function test_manager_is_redirected_from_admin_only_settings(string $routeName): void
    {
        $this->actingAs($this->manager())
            ->get(route($routeName))
            ->assertRedirect(route('admin.settings.general'));
    }

    /**
     * @dataProvider adminOnlySettingsRoutes
     */
    public function test_admin_can_open_every_settings_screen(string $routeName): void
    {
        $this->actingAs($this->admin())
            ->get(route($routeName))
            ->assertOk();
    }

    // ── General page content gating ───────────────────────────────────────────

    public function test_general_page_hides_config_card_for_manager(): void
    {
        $response = $this->actingAs($this->manager())->get(route('admin.settings.general'));

        $response->assertOk();
        $response->assertSee('Оповещения о новых сообщениях');
        $response->assertDontSee('ID группы для приёма сообщений');
        // Sidebar: only «Основные» — other settings links hidden.
        $response->assertDontSee('API и вебхуки');
        $response->assertDontSee('Команда');
    }

    public function test_general_page_shows_config_card_and_full_nav_for_admin(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.settings.general'));

        $response->assertOk();
        $response->assertSee('ID группы для приёма сообщений');
        $response->assertSee('API и вебхуки');
        $response->assertSee('Команда');
    }

    // ── save() guard ──────────────────────────────────────────────────────────

    public function test_manager_cannot_persist_config_via_save(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(GeneralSettingsPage::class)
            ->set('group_id', '-100999999')
            ->set('template_topic_name', 'hacked')
            ->call('save')
            ->assertSet('saved', false);

        // The manager's injected values must NOT be persisted (save() refused).
        $settings = app(SettingsService::class);
        $this->assertNotSame('-100999999', (string) ($settings->get('telegram.group_id') ?? ''));
        $this->assertNotSame('hacked', (string) ($settings->get('telegram.template_topic_name') ?? ''));
    }
}
