<?php

namespace App\Modules\Api\Services;

use App\Modules\Api\Exceptions\FileProxyException;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileService
{
    private string $botToken;

    public function __construct()
    {
        $this->botToken = (string) app(SettingsService::class)->get('telegram.token');
    }

    public function streamFile(string $fileId, string $disposition = 'inline'): StreamedResponse
    {
        if ($fileId === '' || !in_array($disposition, ['inline', 'attachment'], true)) {
            throw new FileProxyException('invalid_request', 403);
        }

        $file = $this->getTelegramFile($fileId);
        $filePath = $file['result']['file_path'] ?? null;
        $fileSize = $file['result']['file_size'] ?? null;

        if (!is_string($filePath) || $filePath === '') {
            throw new FileProxyException('file_not_found', 404);
        }

        $maxBytes = (int) config('file_proxy.max_bytes', 20 * 1024 * 1024);
        if (is_numeric($fileSize) && (int) $fileSize > $maxBytes) {
            throw new FileProxyException('file_too_large', 413);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'tg-file-');
        if ($temporaryPath === false) {
            throw new FileProxyException('temporary_storage_failed', 502);
        }

        try {
            $response = $this->downloadTelegramFile($filePath, $temporaryPath, $maxBytes);
            $contentLength = (int) ($response->header('Content-Length') ?: filesize($temporaryPath));

            if ($contentLength > $maxBytes || filesize($temporaryPath) > $maxBytes) {
                throw new FileProxyException('file_too_large', 413);
            }

            $filename = $this->safeFilename($filePath);
            $headers = [
                'Content-Type' => $this->getFileContentType($filePath),
                'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
                'Content-Length' => (string) filesize($temporaryPath),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ];

            register_shutdown_function(static function () use ($temporaryPath): void {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            });

            return response()->stream(static function () use ($temporaryPath): void {
                try {
                    $stream = fopen($temporaryPath, 'rb');
                    if ($stream !== false) {
                        fpassthru($stream);
                        fclose($stream);
                    }
                } finally {
                    if (is_file($temporaryPath)) {
                        @unlink($temporaryPath);
                    }
                }
            }, 200, $headers);
        } catch (\Throwable $e) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw $e;
        }
    }

    public function downloadFile(string $fileId): StreamedResponse
    {
        return $this->streamFile($fileId, 'attachment');
    }

    public function getTelegramFile(string $fileId): array
    {
        if ($this->botToken === '') {
            throw new FileProxyException('upstream_unavailable', 502);
        }

        try {
            $response = Http::connectTimeout((int) config('file_proxy.connect_timeout', 3))
                ->timeout((int) config('file_proxy.timeout', 15))
                ->withoutRedirecting()
                ->get("https://api.telegram.org/bot{$this->botToken}/getFile", [
                    'file_id' => $fileId,
                ]);
        } catch (ConnectionException $e) {
            throw new FileProxyException('upstream_timeout', 504, $e);
        }

        $this->assertTelegramResponse($response);
        $json = $response->json();

        if (!is_array($json) || !array_key_exists('ok', $json)) {
            throw new FileProxyException('upstream_invalid_response', 502);
        }

        if ($json['ok'] !== true) {
            throw new FileProxyException('file_not_found', 404);
        }

        return $json;
    }

    protected function downloadTelegramFile(string $filePath, string $temporaryPath, int $maxBytes): Response
    {
        $sizeExceeded = false;

        try {
            $response = Http::connectTimeout((int) config('file_proxy.connect_timeout', 3))
                ->timeout((int) config('file_proxy.timeout', 15))
                ->withoutRedirecting()
                ->withOptions([
                    'sink' => $temporaryPath,
                    'progress' => static function (int $downloadSize, int $downloaded, int $uploadSize, int $uploaded) use ($maxBytes, &$sizeExceeded): void {
                        if ($downloadSize > $maxBytes || $downloaded > $maxBytes) {
                            $sizeExceeded = true;
                            throw new \RuntimeException('file_size_limit_exceeded');
                        }
                    },
                ])
                ->get("https://api.telegram.org/file/bot{$this->botToken}/{$filePath}");
        } catch (ConnectionException $e) {
            if ($sizeExceeded) {
                throw new FileProxyException('file_too_large', 413, $e);
            }

            throw new FileProxyException('upstream_timeout', 504, $e);
        } catch (\Throwable $e) {
            if ($sizeExceeded) {
                throw new FileProxyException('file_too_large', 413, $e);
            }

            throw new FileProxyException('upstream_error', 502, $e);
        }

        $this->assertTelegramResponse($response);

        return $response;
    }

    private function assertTelegramResponse(Response $response): void
    {
        if ($response->status() === 429) {
            throw new FileProxyException('upstream_rate_limited', 429);
        }

        if ($response->serverError()) {
            throw new FileProxyException('upstream_error', 502);
        }

        if ($response->clientError()) {
            throw new FileProxyException('file_not_found', 404);
        }
    }

    private function safeFilename(string $filePath): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filePath));

        return is_string($filename) && $filename !== '' ? $filename : 'telegram-file';
    }

    protected function getFileContentType(string $filePath): string
    {
        $mapping = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'txt' => 'text/plain; charset=UTF-8',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
        ];

        return $mapping[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))]
            ?? 'application/octet-stream';
    }
}
