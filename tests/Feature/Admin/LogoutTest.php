<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_control_visible_on_chat_workspace(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.chats'))
            ->assertOk()
            ->assertSee('admin/logout');
    }

    public function test_logout_control_visible_on_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.settings.general'))
            ->assertOk()
            ->assertSee('Выйти')
            ->assertSee('admin/logout');
    }

    public function test_logout_logs_the_user_out(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // CSRF is enforced for the POST; the real form ships @csrf. Here we
        // exercise the logout logic itself, so skip the CSRF middleware.
        $this->actingAs($admin)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect();

        $this->assertGuest();
    }
}
