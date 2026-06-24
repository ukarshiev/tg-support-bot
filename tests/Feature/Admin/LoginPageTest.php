<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_custom_design(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk()
            ->assertSee('TG Support Bot')
            ->assertSee('Вход в систему')
            ->assertSee('Введите свои данные для доступа к панели управления')
            ->assertSee('Войти');
    }

    public function test_valid_credentials_authenticate_via_custom_login(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('secret123'),
            'role' => UserRole::Admin,
        ]);

        \Livewire\Livewire::test(\App\Livewire\Auth\LoginPage::class)
            ->set('email', 'admin@example.com')
            ->set('password', 'secret123')
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }
}
