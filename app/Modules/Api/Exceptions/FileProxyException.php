<?php

namespace App\Modules\Api\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FileProxyException extends RuntimeException
{
    private string $traceId;

    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        ?\Throwable $previous = null,
    ) {
        $this->traceId = (string) Str::uuid();
        parent::__construct($errorCode, 0, $previous);
    }

    public function report(): bool
    {
        Log::channel('app')->warning('file_proxy_failed', [
            'error_code' => $this->errorCode,
            'trace_id' => $this->traceId,
        ]);

        return true;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Unable to retrieve file.',
            'error_code' => $this->errorCode,
            'trace_id' => $this->traceId,
        ], $this->status);
    }
}
