<?php

namespace Tests\Mocks;

use App\Modules\Api\Services\FileService;

/** Keep PHPUnit output buffers intact while exercising the real HTTP sink. */
class StreamingFileService extends FileService
{
    /** @return resource An in-memory stand-in for the non-seekable PHP output. */
    protected function openOutputStream()
    {
        $out = fopen('php://memory', 'w+b');
        if ($out === false) {
            throw new \RuntimeException('Unable to open test output.');
        }

        return $out;
    }

    /**
     * Http::fake writes into the sink and rewinds it; emit that captured body.
     *
     * @param resource $out The test-only output stream.
     */
    protected function downloadTelegramFile(string $filePath, $out, ?string $range = null): void
    {
        try {
            parent::downloadTelegramFile($filePath, $out, $range);
        } finally {
            rewind($out);
            fpassthru($out);
        }
    }
}
