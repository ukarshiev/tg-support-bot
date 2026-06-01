<?php

declare(strict_types=1);

namespace App\Modules\Admin\Actions;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Create a new operator account with a generated password.
 *
 * Flow:
 *  1. Generate a secure random password (16 chars).
 *  2. Create the User record (password cast is 'hashed' so plain text is stored hashed).
 *
 * No email is sent — the generated plain-text password is returned so the admin can
 * hand it to the operator directly.
 *
 * IMPORTANT: the returned plain-text password MUST NOT be logged.
 */
final class InviteOperator
{
    /**
     * Create a new operator and return it together with the generated plain password.
     *
     * @param string   $email Valid, unique email address for the new operator.
     * @param UserRole $role  Role to assign (Admin or Manager).
     *
     * @return array{user: User, password: string}
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

        return [
            'user' => $user,
            'password' => $plainPassword,
        ];
    }
}
