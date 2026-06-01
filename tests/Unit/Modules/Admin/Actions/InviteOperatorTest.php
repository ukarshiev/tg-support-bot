<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Admin\Actions;

use App\Enums\UserRole;
use App\Mail\OperatorInvitationMail;
use App\Models\User;
use App\Modules\Admin\Actions\InviteOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InviteOperatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_user_with_given_email_and_role(): void
    {
        Mail::fake();

        $user = InviteOperator::execute('new@example.com', UserRole::Manager);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'manager']);
    }

    public function test_creates_admin_role_user(): void
    {
        Mail::fake();

        $user = InviteOperator::execute('admin@example.com', UserRole::Admin);

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }

    public function test_password_is_hashed_in_database(): void
    {
        Mail::fake();

        $user = InviteOperator::execute('hashed@example.com', UserRole::Manager);

        // The stored password must be a bcrypt hash, never plain text.
        $this->assertNotNull($user->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::isHashed($user->password));
    }

    public function test_queues_invitation_mail_to_correct_address(): void
    {
        Mail::fake();

        InviteOperator::execute('queued@example.com', UserRole::Manager);

        Mail::assertQueued(OperatorInvitationMail::class, function (OperatorInvitationMail $mail): bool {
            return $mail->hasTo('queued@example.com');
        });
    }

    public function test_queues_exactly_one_mail(): void
    {
        Mail::fake();

        InviteOperator::execute('once@example.com', UserRole::Manager);

        Mail::assertQueuedCount(1);
    }

    public function test_mailable_carries_non_empty_password(): void
    {
        Mail::fake();

        InviteOperator::execute('pw@example.com', UserRole::Manager);

        Mail::assertQueued(OperatorInvitationMail::class, function (OperatorInvitationMail $mail): bool {
            return strlen($mail->password) >= 16;
        });
    }

    public function test_returns_persisted_user(): void
    {
        Mail::fake();

        $user = InviteOperator::execute('persisted@example.com', UserRole::Admin);

        $this->assertNotNull($user->id);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
