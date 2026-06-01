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

        $result = InviteOperator::execute('new@example.com', UserRole::Manager);
        $user = $result['user'];

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertTrue($result['mail_sent']);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'manager']);
    }

    public function test_creates_admin_role_user(): void
    {
        Mail::fake();

        $result = InviteOperator::execute('admin@example.com', UserRole::Admin);

        $this->assertSame(UserRole::Admin, $result['user']->role);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);
    }

    public function test_password_is_hashed_in_database(): void
    {
        Mail::fake();

        $result = InviteOperator::execute('hashed@example.com', UserRole::Manager);

        // The stored password must be a bcrypt hash, never plain text.
        $this->assertNotNull($result['user']->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::isHashed($result['user']->password));
    }

    public function test_returns_plain_password_of_expected_length(): void
    {
        Mail::fake();

        $result = InviteOperator::execute('plain@example.com', UserRole::Manager);

        $this->assertArrayHasKey('password', $result);
        $this->assertSame(16, strlen($result['password']));
        // The returned plain password must differ from the stored hash.
        $this->assertNotSame($result['password'], $result['user']->password);
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

        $result = InviteOperator::execute('persisted@example.com', UserRole::Admin);

        $this->assertNotNull($result['user']->id);
        $this->assertDatabaseHas('users', ['id' => $result['user']->id]);
    }

    public function test_user_is_still_created_when_mail_delivery_fails(): void
    {
        // Simulate an unavailable mailer (e.g. no SMTP / sync queue sending inline).
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('queue')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $result = InviteOperator::execute('mailfail@example.com', UserRole::Manager);

        $this->assertFalse($result['mail_sent']);
        $this->assertSame(16, strlen($result['password']));
        $this->assertDatabaseHas('users', ['email' => 'mailfail@example.com', 'role' => 'manager']);
    }
}
