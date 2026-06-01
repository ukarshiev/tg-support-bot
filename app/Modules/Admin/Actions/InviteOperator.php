<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Enums\UserRole;
use App\Mail\OperatorInvitationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Create a new operator account and queue an invitation email with a generated password.
 *
 * Flow:
 *  1. Generate a secure random password (16 chars).
 *  2. Create the User record (password cast is 'hashed' so plain text is stored hashed).
 *  3. Best-effort: queue OperatorInvitationMail with the plain-text password.
 *
 * Creating the operator is the primary outcome; email delivery is best-effort. If the
 * mailer is unavailable (e.g. no SMTP configured, or a `sync` queue sending inline), the
 * user is STILL created and the failure is reported — the caller receives `mail_sent=false`
 * together with the plain-text password so it can be delivered to the operator manually.
 *
 * IMPORTANT: the plain-text password is passed to the Mailable and returned to the caller,
 * but MUST NOT be logged. Only the transport exception is reported on mail failure.
 */
final class InviteOperator
{
    /**
     * Create a new user and (best-effort) dispatch the invitation email.
     *
     * @param string   $email Valid, unique email address for the new operator.
     * @param UserRole $role  Role to assign (Admin or Manager).
     *
     * @return array{user: User, password: string, mail_sent: bool}
     */
    public static function execute(string $email, UserRole $role): array
    {
        $plainPassword = Str::password(16);

        /** @var User $user */
        $user = User::create([
            'name' => '',
            'email' => $email,
            'password' => $plainPassword,
            'role' => $role,
        ]);

        $mailSent = true;

        try {
            Mail::to($email)->queue(new OperatorInvitationMail($user, $plainPassword));
        } catch (\Throwable $e) {
            // Email delivery is best-effort — the operator account is already created.
            // Report only the transport error; never log the password.
            $mailSent = false;
            report($e);
        }

        return [
            'user' => $user,
            'password' => $plainPassword,
            'mail_sent' => $mailSent,
        ];
    }
}
