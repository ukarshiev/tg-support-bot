<?php

namespace App\Modules\Api\Services;

use Illuminate\Support\Facades\URL;

class TelegramFileUrlGenerator
{
    public function generate(string $fileId, string $disposition = 'inline'): string
    {
        if (!in_array($disposition, ['inline', 'attachment'], true)) {
            throw new \InvalidArgumentException('Unsupported file disposition.');
        }

        $relativeUrl = URL::temporarySignedRoute(
            'stream_file',
            now()->addMinutes((int) config('file_proxy.url_ttl_minutes', 15)),
            ['file_id' => $fileId, 'disposition' => $disposition],
            absolute: false,
        );

        return rtrim((string) config('app.url'), '/') . $relativeUrl;
    }
}
