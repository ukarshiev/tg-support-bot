<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\TeamPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_renders_invite_card_heading(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->assertSee('Пригласить оператора');
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

    // ── Invite — happy path ────────────────────────────────────────────────────

    public function test_invite_creates_user_with_chosen_role(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'newop@example.com')
            ->set('inviteRole', 'manager')
            ->call('invite');

        $this->assertDatabaseHas('users', [
            'email' => 'newop@example.com',
            'role' => 'manager',
        ]);
    }

    public function test_invite_reveals_generated_password(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'reveal@example.com')
            ->set('inviteRole', 'manager')
            ->call('invite');

        $password = $component->get('invitedPassword');
        $this->assertNotNull($password);
        $this->assertSame(16, strlen($password));

        // The revealed password must match the stored hash.
        $user = User::where('email', 'reveal@example.com')->firstOrFail();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->password));
    }

    public function test_dismiss_invited_password_clears_reveal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'dismiss@example.com')
            ->set('inviteRole', 'manager')
            ->call('invite')
            ->assertNotSet('invitedPassword', null)
            ->call('dismissInvitedPassword')
            ->assertSet('invitedPassword', null);
    }

    public function test_invite_shows_success_notice_and_resets_form(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'success@example.com')
            ->set('inviteRole', 'manager')
            ->call('invite');

        $component
            ->assertSet('inviteEmail', '')
            ->assertSet('inviteRole', '');

        $this->assertStringContainsString('success@example.com', $component->get('inviteSuccess'));
    }

    // ── Invite — validation ────────────────────────────────────────────────────

    public function test_invite_rejects_empty_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', '')
            ->set('inviteRole', 'manager')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        $this->assertSame(1, User::count()); // only the admin
    }

    public function test_invite_rejects_invalid_email_format(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'not-an-email')
            ->set('inviteRole', 'manager')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);
    }

    public function test_invite_rejects_duplicate_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $existing = User::factory()->manager()->create(['email' => 'dup@example.com']);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'dup@example.com')
            ->set('inviteRole', 'manager')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        $this->assertSame(1, User::where('email', 'dup@example.com')->count());
    }

    public function test_invite_rejects_missing_role(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'norole@example.com')
            ->set('inviteRole', '')
            ->call('invite')
            ->assertHasErrors(['inviteRole']);
    }

    public function test_invite_rejects_invalid_role(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        Livewire::test(TeamPage::class)
            ->set('inviteEmail', 'badrole@example.com')
            ->set('inviteRole', 'superuser')
            ->call('invite')
            ->assertHasErrors(['inviteRole']);
    }

    // ── Delete — happy path ────────────────────────────────────────────────────

    public function test_confirm_delete_sets_confirm_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->call('confirmDelete', $member->id)
            ->assertSet('confirmDeleteId', $member->id);
    }

    public function test_cancel_delete_clears_confirm_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->call('confirmDelete', $member->id)
            ->call('cancelDelete')
            ->assertSet('confirmDeleteId', null);
    }

    public function test_delete_member_removes_user_from_database(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->call('confirmDelete', $member->id)
            ->call('deleteMember');

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_delete_member_resets_confirm_id_after_delete(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $member = User::factory()->manager()->create();

        Livewire::test(TeamPage::class)
            ->call('confirmDelete', $member->id)
            ->call('deleteMember')
            ->assertSet('confirmDeleteId', null);
    }

    // ── Delete — self-lockout protection ──────────────────────────────────────

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $component = Livewire::test(TeamPage::class)
            ->call('confirmDelete', $admin->id)
            ->call('deleteMember');

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
            ->call('confirmDelete', $admin->id)
            ->call('deleteMember')
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
