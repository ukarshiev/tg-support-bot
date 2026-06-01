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
 *  3. Queue OperatorInvitationMail with the plain-text password (Mail driver is 'log' locally).
 *
 * IMPORTANT: the plain-text password is passed to the Mailable and MUST NOT be logged.
 * After execute() returns the caller can safely discard the plain-text value.
 */
final class InviteOperator
{
    /**
     * Create a new user and dispatch a queued invitation email.
     *
     * @param string   $email Valid, unique email address for the new operator.
     * @param UserRole $role  Role to assign (Admin or Manager).
     *
     * @return User The newly created user.
     */
    public static function execute(string $email, UserRole $role): User
    {
        $plainPassword = Str::password(16);

        /** @var User $user */
        $user = User::create([
            'name' => '',
            'email' => $email,
            'password' => $plainPassword,
            'role' => $role,
        ]);

        Mail::to($email)->queue(new OperatorInvitationMail($user, $plainPassword));

        return $user;
    }
}
