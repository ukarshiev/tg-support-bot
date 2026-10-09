<?php

namespace App\Modules\Api\Controllers;

use App\Modules\Api\Services\FileService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Serve signed Telegram file previews and downloads. */
class FilesController
{
    /** Inject the file proxy without passing HTTP requests into its service. */
    public function __construct(private FileService $fileService)
    {
    }

    /** Forward the requested disposition and optional byte range to the proxy. */
    public function getFileStream(Request $request, string $fileId): StreamedResponse
    {
        $range = $request->header('Range');

        return $this->fileService->streamFile(
            $fileId,
            (string) $request->query('disposition', 'inline'),
            is_string($range) ? $range : null,
        );
    }

    /**
     * Preserve the legacy signed download route, including byte ranges.
     *
     * @deprecated Use the signed GET endpoint with disposition=attachment.
     */
    public function getFileDownload(Request $request, string $fileId): StreamedResponse
    {
        $range = $request->header('Range');
        $response = $this->fileService->streamFile(
            $fileId,
            (string) $request->query('disposition', 'attachment'),
            is_string($range) ? $range : null,
        );
        $response->headers->set('Deprecation', 'true');

        return $response;
    }
}
