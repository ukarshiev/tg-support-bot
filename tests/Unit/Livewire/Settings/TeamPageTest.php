<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\TeamPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeamPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.settings.team'));

        $response->assertRedirectContains('/admin/login');
    }

    public function test_admin_can_render_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->assertSuccessful();
    }

    public function test_manager_is_redirected_on_mount(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);

        Livewire::test(TeamPage::class)
            ->assertRedirect(route('admin.settings.general'));
    }

    // ── Route & heading ────────────────────────────────────────────────────────

    public function test_route_admin_settings_team_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.settings.team'));
    }

    public function test_renders_page_title_and_subtitle(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->assertSee('Команда')
            ->assertSee('Управление операторами');
    }

    public function test_renders_add_button_linking_to_create_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->assertSee('Добавить')
            ->assertSeeHtml(route('admin.settings.team.create'));
    }

    public function test_member_row_links_to_edit_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->assertSeeHtml(route('admin.settings.team.edit', $member->id));
    }

    public function test_renders_members_table_heading(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->assertSee('Участники команды');
    }

    // ── Members list ───────────────────────────────────────────────────────────

    public function test_members_are_displayed(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'name' => 'Алексей Козлов',
            'email' => 'a.kozlov@example.com',
        ]);
        $this->actingAs($admin);

        $manager = User::factory()->manager()->create([
            'name' => 'Мария Смирнова',
            'email' => 'm.smirnova@example.com',
        ]);

        Livewire::test(TeamPage::class)
            ->assertSee('Алексей Козлов')
            ->assertSee('a.kozlov@example.com')
            ->assertSee('Мария Смирнова')
            ->assertSee('m.smirnova@example.com');
    }

    public function test_empty_state_shown_when_no_members(): void
    {
        // Use a factory admin so the DB is otherwise empty
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Delete all users (including admin itself) to force empty state
        User::query()->delete();

        // Re-authenticate with a transient in-memory admin (not persisted)
        $transientAdmin = new User();
        $transientAdmin->forceFill([
            'id' => 9999,
            'name' => 'Temp',
            'email' => 'temp@example.com',
            'role' => UserRole::Admin,
            'password' => 'hashed',
        ])->syncOriginal();

        $this->actingAs($transientAdmin);

        Livewire::test(TeamPage::class)
            ->assertSee('Участников пока нет');
    }

    // ── Delete — happy path ────────────────────────────────────────────────────

    public function test_delete_member_removes_user_from_database(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->call('deleteMember', $member->id);

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    // ── Delete — self-lockout protection ──────────────────────────────────────

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(TeamPage::class)
            ->call('deleteMember', $admin->id);

        // User must still exist in DB
        $this->assertDatabaseHas('users', ['id' => $admin->id]);

        // An error message must be set
        $this->assertNotNull($component->get('deleteError'));
    }

    public function test_admin_self_delete_shows_error_message(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->call('deleteMember', $admin->id)
            ->assertSee('Вы не можете удалить');
    }

    // ── Avatar helpers ─────────────────────────────────────────────────────────

    public function test_avatar_initials_from_two_word_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $user = new User();
        $user->name = 'Алексей Козлов';
        $user->email = 'a@example.com';

        $page = new TeamPage();
        $this->assertSame('АК', $page->avatarInitials($user));
    }

    public function test_avatar_initials_from_single_word_name(): void
    {
        $user = new User();
        $user->name = 'Алексей';
        $user->email = 'a@example.com';

        $page = new TeamPage();
        $initials = $page->avatarInitials($user);

        $this->assertSame(2, mb_strlen($initials));
    }

    public function test_avatar_initials_fallback_to_email_local_part(): void
    {
        $user = new User();
        $user->name = '';
        $user->email = 'op@example.com';

        $page = new TeamPage();
        $this->assertSame('OP', $page->avatarInitials($user));
    }

    public function test_avatar_color_returns_hex_value(): void
    {
        $user = new User();
        $user->email = 'color@example.com';

        $page = new TeamPage();
        $color = $page->avatarColor($user);

        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color);
    }

    public function test_avatar_color_is_deterministic(): void
    {
        $user = new User();
        $user->email = 'same@example.com';

        $page = new TeamPage();
        $this->assertSame($page->avatarColor($user), $page->avatarColor($user));
    }
}
