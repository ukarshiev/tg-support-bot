<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\TeamMemberCreatePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TeamMemberCreatePageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_renders_all_form_fields(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->assertOk()
            ->assertSee('Новый участник')
            ->assertSee('Имя')
            ->assertSee('Email')
            ->assertSee('Пароль')
            ->assertSee('Подтверждение пароля')
            ->assertSee('Роль');
    }

    public function test_creates_user_and_redirects_to_team_list(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Пётр Иванов')
            ->set('email', 'petr@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'manager')
            ->call('save')
            ->assertRedirect(route('admin.settings.team'));

        $this->assertDatabaseHas('users', [
            'name' => 'Пётр Иванов',
            'email' => 'petr@example.com',
            'role' => 'manager',
        ]);
    }

    public function test_created_password_is_hashed(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Test')
            ->set('email', 'hash@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'manager')
            ->call('save');

        $user = User::where('email', 'hash@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotSame('secret123', $user->password);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_requires_all_fields(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->call('save')
            ->assertHasErrors(['name', 'email', 'password', 'role']);

        // Only the acting admin exists.
        $this->assertSame(1, User::query()->count());
    }

    public function test_password_must_be_confirmed(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Test')
            ->set('email', 'mismatch@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'different1')
            ->set('role', 'manager')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    public function test_rejects_duplicate_email(): void
    {
        $this->actingAdmin();
        User::factory()->create(['email' => 'dup@example.com']);

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Test')
            ->set('email', 'dup@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'manager')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_rejects_invalid_role(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Test')
            ->set('email', 'badrole@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'superuser')
            ->call('save')
            ->assertHasErrors(['role']);
    }

    public function test_non_admin_is_redirected(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

        Livewire::test(TeamMemberCreatePage::class)
            ->assertRedirect(route('admin.settings.general'));
    }
}
