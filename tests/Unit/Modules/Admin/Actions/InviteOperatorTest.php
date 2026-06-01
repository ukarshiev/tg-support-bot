<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Actions;

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Admin\Actions\InviteOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteOperatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_user_with_given_email_and_role(): void
    {
        $result = InviteOperator::execute('new@example.com', UserRole::Manager);
        $user = $result['user'];

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'manager']);
    }

    public function test_creates_admin_role_user(): void
    {
        $result = InviteOperator::execute('admin@example.com', UserRole::Admin);

        $this->assertSame(UserRole::Admin, $result['user']->role);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }

    public function test_password_is_hashed_in_database(): void
    {
        $result = InviteOperator::execute('hashed@example.com', UserRole::Manager);

        // The stored password must be a bcrypt hash, never plain text.
        $this->assertNotNull($result['user']->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::isHashed($result['user']->password));
    }

    public function test_returns_plain_password_of_expected_length(): void
    {
        $result = InviteOperator::execute('plain@example.com', UserRole::Manager);

        $this->assertArrayHasKey('password', $result);
        $this->assertSame(16, strlen($result['password']));
        // The returned plain password must differ from the stored hash.
        $this->assertNotSame($result['password'], $result['user']->password);
    }

    public function test_generated_password_matches_stored_hash(): void
    {
        $result = InviteOperator::execute('match@example.com', UserRole::Manager);

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check($result['password'], $result['user']->password)
        );
    }

    public function test_returns_persisted_user(): void
    {
        $result = InviteOperator::execute('persisted@example.com', UserRole::Admin);

        $this->assertNotNull($result['user']->id);
        $this->assertDatabaseHas('users', ['id' => $result['user']->id]);
    }
}
