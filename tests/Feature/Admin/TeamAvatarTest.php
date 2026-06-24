<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Settings\TeamMemberCreatePage;
use App\Livewire\Settings\TeamMemberEditPage;
use App\Livewire\Settings\TeamPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for team member avatar upload, replace, and removal.
 *
 * Covers:
 *  - Creating a member with an avatar stores the file and sets avatar_path
 *  - Creating a member without an avatar leaves avatar_path null
 *  - Editing a member with a new avatar replaces the stored file
 *  - Removing an avatar via removeAvatar() deletes the file and nulls avatar_path
 *  - Deleting a member removes the avatar file from disk
 *  - TeamPage list shows an <img> when avatar_path is set, initials otherwise
 */
class TeamAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        return $admin;
    }

    // ── Create page — avatar upload ────────────────────────────────────────────

    public function test_create_member_with_avatar_stores_file_and_sets_avatar_path(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        $file = UploadedFile::fake()->image('photo.jpg');

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Пётр Иванов')
            ->set('email', 'petr@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'manager')
            ->set('avatar', $file)
            ->call('save')
            ->assertRedirect(route('admin.settings.team'));

        $user = User::where('email', 'petr@example.com')->firstOrFail();

        $this->assertNotNull($user->avatar_path);
        $this->assertStringStartsWith('avatars/user-', $user->avatar_path);
        Storage::disk('local')->assertExists($user->avatar_path);
    }

    public function test_create_member_without_avatar_leaves_avatar_path_null(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        Livewire::test(TeamMemberCreatePage::class)
            ->set('name', 'Мария Смирнова')
            ->set('email', 'maria@example.com')
            ->set('password', 'secret123')
            ->set('password_confirmation', 'secret123')
            ->set('role', 'manager')
            ->call('save');

        $user = User::where('email', 'maria@example.com')->firstOrFail();
        $this->assertNull($user->avatar_path);
    }

    public function test_create_rejects_non_image_file(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        Livewire::test(TeamMemberCreatePage::class)
            ->set('avatar', $file)
            ->call('save')
            ->assertHasErrors(['avatar']);
    }

    public function test_create_rejects_image_exceeding_2mb(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        // 2049 KB — just over the 2 MB limit
        $file = UploadedFile::fake()->image('big.jpg')->size(2049);

        Livewire::test(TeamMemberCreatePage::class)
            ->set('avatar', $file)
            ->call('save')
            ->assertHasErrors(['avatar']);
    }

    // ── Edit page — avatar replace ─────────────────────────────────────────────

    public function test_edit_member_with_new_avatar_replaces_file(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        $member = User::factory()->manager()->create();
        $oldPath = "avatars/user-{$member->id}.jpg";
        Storage::disk('local')->put($oldPath, 'OLD-AVATAR');
        $member->update(['avatar_path' => $oldPath]);

        $newFile = UploadedFile::fake()->image('new.jpg');

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->set('avatar', $newFile)
            ->call('save')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertNotNull($member->avatar_path);
        Storage::disk('local')->assertExists($member->avatar_path);
    }

    // ── Edit page — remove avatar ──────────────────────────────────────────────

    public function test_remove_avatar_deletes_file_and_nulls_avatar_path(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        $member = User::factory()->manager()->create();
        $path = "avatars/user-{$member->id}.jpg";
        Storage::disk('local')->put($path, 'AVATAR-BYTES');
        $member->update(['avatar_path' => $path]);

        Livewire::test(TeamMemberEditPage::class, ['user' => $member->id])
            ->call('removeAvatar')
            ->assertSet('currentAvatarPath', null);

        $member->refresh();
        $this->assertNull($member->avatar_path);
        Storage::disk('local')->assertMissing($path);
    }

    // ── Team list page — avatar display ───────────────────────────────────────

    public function test_team_list_shows_img_when_member_has_avatar(): void
    {
        Storage::fake('local');
        $admin = $this->actingAdmin();

        $member = User::factory()->manager()->create();
        $path = "avatars/user-{$member->id}.jpg";
        Storage::disk('local')->put($path, 'AVATAR');
        $member->update(['avatar_path' => $path]);

        Livewire::test(TeamPage::class)
            ->assertSeeHtml(route('admin.team-member-avatar', $member->id));
    }

    public function test_team_list_shows_initials_when_member_has_no_avatar(): void
    {
        $this->actingAdmin();

        $member = User::factory()->manager()->create([
            'name' => 'Анна Козлова',
            'avatar_path' => null,
        ]);

        Livewire::test(TeamPage::class)
            ->assertSee('АК');
    }

    // ── Delete member — avatar cleanup ─────────────────────────────────────────

    public function test_delete_member_removes_avatar_file_from_disk(): void
    {
        Storage::fake('local');
        $admin = $this->actingAdmin();

        $member = User::factory()->manager()->create();
        $path = "avatars/user-{$member->id}.jpg";
        Storage::disk('local')->put($path, 'AVATAR-BYTES');
        $member->update(['avatar_path' => $path]);

        Livewire::test(TeamPage::class)
            ->call('deleteMember', $member->id);

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_member_without_avatar_does_not_error(): void
    {
        Storage::fake('local');
        $this->actingAdmin();

        $member = User::factory()->manager()->create(['avatar_path' => null]);

        Livewire::test(TeamPage::class)
            ->call('deleteMember', $member->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }
}
