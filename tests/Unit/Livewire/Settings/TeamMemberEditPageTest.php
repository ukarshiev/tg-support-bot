<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Enums\UserRole;
use App\Livewire\Settings\TeamMemberEditPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TeamMemberEditPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_prefills_fields_from_user(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create(['name' => 'Пётр', 'email' => 'petr@example.com']);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->assertOk()
            ->assertSee('Редактирование участника')
            ->assertSet('userId', $member->id)
            ->assertSet('name', 'Пётр')
            ->assertSet('email', 'petr@example.com')
            ->assertSet('role', 'manager');
    }

    public function test_save_updates_name_email_and_role(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create(['name' => 'Old', 'email' => 'old@example.com']);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('name', 'Новый')
            ->set('email', 'new@example.com')
            ->set('role', 'admin')
            ->call('save')
            ->assertRedirect(route('admin.settings.team'));

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Новый',
            'email' => 'new@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_blank_password_keeps_current_one(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create(['password' => Hash::make('original1')]);
        $originalHash = $member->password;

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('name', 'Keep')
            ->set('password', '')
            ->set('password_confirmation', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($originalHash, $member->fresh()->password);
    }

    public function test_filled_password_is_updated_and_hashed(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create(['password' => Hash::make('original1')]);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('password', 'brandnew1')
            ->set('password_confirmation', 'brandnew1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brandnew1', $member->fresh()->password));
    }

    public function test_email_unique_ignores_self(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create(['email' => 'mine@example.com']);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('email', 'mine@example.com')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_email_must_be_unique_against_others(): void
    {
        $this->actingAdmin();
        User::factory()->create(['email' => 'taken@example.com']);
        $member = User::factory()->manager()->create(['email' => 'mine@example.com']);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('email', 'taken@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    }

    public function test_password_confirmation_must_match_when_provided(): void
    {
        $this->actingAdmin();
        $member = User::factory()->manager()->create();

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('password', 'brandnew1')
            ->set('password_confirmation', 'different1')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    public function test_unknown_user_redirects_to_team_list(): void
    {
        $this->actingAdmin();

        Livewire::test(TeamMemberEditPage::class, ['user' => 999999])
            ->assertRedirect(route('admin.settings.team'));
    }

    public function test_non_admin_is_redirected(): void
    {
        $member = User::factory()->manager()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager]));

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->assertRedirect(route('admin.settings.general'));
    }
}
