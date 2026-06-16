<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for UserAvatarController — the auth-gated route that streams
 * locally-stored team member avatar images.
 */
class UserAvatarControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithAvatar(string $avatarPath): User
    {
        return User::factory()->create([
            'role' => UserRole::Manager,
            'avatar_path' => $avatarPath,
        ]);
    }

    public function test_serves_avatar_to_authed_user(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['role' => UserRole::Manager]);
        $storagePath = "avatars/user-{$user->id}.jpg";
        Storage::disk('local')->put($storagePath, 'AVATAR-BYTES');
        $user->update(['avatar_path' => $storagePath]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.team-member-avatar', $user->id))
            ->assertOk()
            ->assertStreamedContent('AVATAR-BYTES');
    }

    public function test_guests_are_redirected(): void
    {
        Storage::fake('local');

        $user = $this->makeUserWithAvatar('avatars/user-9.jpg');
        Storage::disk('local')->put('avatars/user-9.jpg', 'X');

        $this->get(route('admin.team-member-avatar', $user->id))
            ->assertRedirect();
    }

    public function test_404_when_path_does_not_start_with_avatars(): void
    {
        $user = $this->makeUserWithAvatar('etc/passwd');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.team-member-avatar', $user->id))
            ->assertNotFound();
    }

    public function test_404_when_path_contains_directory_traversal(): void
    {
        $user = $this->makeUserWithAvatar('avatars/../etc/passwd');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.team-member-avatar', $user->id))
            ->assertNotFound();
    }

    public function test_404_when_avatar_path_is_empty(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager, 'avatar_path' => null]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.team-member-avatar', $user->id))
            ->assertNotFound();
    }

    public function test_404_when_file_missing_on_disk(): void
    {
        Storage::fake('local');

        $user = $this->makeUserWithAvatar('avatars/user-missing.jpg');

        $this->actingAs(User::factory()->create())
            ->get(route('admin.team-member-avatar', $user->id))
            ->assertNotFound();
    }
}
