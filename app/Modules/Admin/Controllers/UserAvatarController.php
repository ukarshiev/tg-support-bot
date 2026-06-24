<?php

namespace App\Modules\Admin\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves locally-stored team member (operator) avatars to the admin UI.
 *
 * Avatars are uploaded by the admin via TeamMemberCreatePage / TeamMemberEditPage
 * and stored under avatars/ on the private `local` disk. This auth-gated route
 * streams them back — no dependency on the `public` disk symlink.
 */
class UserAvatarController
{
    /**
     * Stream the user avatar inline.
     *
     * Validates that the stored path is a safe avatars/ path before serving.
     *
     * @param User $user
     *
     * @return StreamedResponse
     */
    public function show(User $user): StreamedResponse
    {
        $path = (string) $user->avatar_path;

        abort_unless(
            $path !== '' && str_starts_with($path, 'avatars/') && ! str_contains($path, '..'),
            404
        );

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
