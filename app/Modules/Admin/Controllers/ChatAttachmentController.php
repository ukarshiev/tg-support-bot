<?php

namespace App\Modules\Admin\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves locally-stored manager-reply attachments to the admin chat thread.
 *
 * Files sent by a manager (e.g. a MAX reply attachment) are stored on the
 * private `local` disk and streamed back through this auth-gated route — no
 * dependency on the `public` disk symlink or the web server serving `/storage`.
 * Only attachments whose `file_id` is a `chat-attachments/` path are served;
 * external (incoming) attachments are rendered directly by their URL.
 */
class ChatAttachmentController
{
    /**
     * Stream the attachment inline.
     *
     * @param MessageAttachment $attachment
     *
     * @return StreamedResponse
     */
    public function show(MessageAttachment $attachment): StreamedResponse
    {
        $path = (string) $attachment->file_id;

        abort_unless(
            str_starts_with($path, 'chat-attachments/') && ! str_contains($path, '..'),
            404
        );

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $attachment->file_name);
    }
}
