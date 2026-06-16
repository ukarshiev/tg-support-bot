<?php

namespace App\Modules\Admin\Controllers;

use App\Models\BotUser;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves locally-stored bot user avatars to the admin chat workspace.
 *
 * Avatars are fetched asynchronously by EnrichBotUserProfileJob and stored
 * under avatars/ on the private `local` disk. This auth-gated route streams
 * them back — no dependency on the `public` disk symlink.
 */
class BotUserAvatarController
{
    /**
     * Stream the avatar inline.
     *
     * Validates that the stored path is a safe avatars/ path before serving.
     *
     * @param BotUser $botUser
     *
     * @return StreamedResponse
     */
    public function show(BotUser $botUser): StreamedResponse
    {
        $path = (string) $botUser->avatar_path;

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
