<?php

namespace Tests\Feature\Admin;

use App\Models\BotUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotUserAvatarControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeBotUserWithAvatar(string $avatarPath): BotUser
    {
        return BotUser::create([
            'chat_id' => 5000,
            'platform' => 'telegram',
            'avatar_path' => $avatarPath,
        ]);
    }

    public function test_serves_avatar_to_authed_admin(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('avatars/bot-user-1.jpg', 'AVATAR-BYTES');

        $botUser = $this->makeBotUserWithAvatar('avatars/bot-user-1.jpg');
        // Update avatar_path to match the actual ID.
        $storagePath = "avatars/bot-user-{$botUser->id}.jpg";
        Storage::disk('local')->put($storagePath, 'AVATAR-BYTES');
        $botUser->update(['avatar_path' => $storagePath]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.bot-user-avatar', $botUser->id))
            ->assertOk();
    }

    public function test_guests_are_redirected(): void
    {
        Storage::fake('local');
        $botUser = BotUser::create(['chat_id' => 5001, 'platform' => 'telegram', 'avatar_path' => 'avatars/x.jpg']);

        $this->get(route('admin.bot-user-avatar', $botUser->id))
            ->assertRedirect();
    }

    public function test_404_when_path_invalid(): void
    {
        $botUser = BotUser::create([
            'chat_id' => 5002,
            'platform' => 'telegram',
            'avatar_path' => 'etc/passwd',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.bot-user-avatar', $botUser->id))
            ->assertNotFound();
    }

    public function test_404_when_file_missing(): void
    {
        Storage::fake('local');

        $botUser = BotUser::create([
            'chat_id' => 5003,
            'platform' => 'telegram',
            'avatar_path' => 'avatars/missing.jpg',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('admin.bot-user-avatar', $botUser->id))
            ->assertNotFound();
    }
}
