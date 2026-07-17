<?php

namespace App\Modules\Api\Controllers;

use App\Modules\Api\Services\FileService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilesController
{
    public function __construct(private FileService $fileService)
    {
    }

    public function getFileStream(Request $request, string $fileId): StreamedResponse
    {
        return $this->fileService->streamFile(
            $fileId,
            (string) $request->query('disposition', 'inline'),
        );
    }

    /**
     * @deprecated Use the signed GET endpoint with disposition=attachment.
     */
    public function getFileDownload(Request $request, string $fileId): StreamedResponse
    {
        $response = $this->fileService->streamFile(
            $fileId,
            (string) $request->query('disposition', 'attachment'),
        );
        $response->headers->set('Deprecation', 'true');

        return $response;
    }
}
