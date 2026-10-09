<?php

namespace App\Modules\Api\Services;

use App\Modules\Api\Exceptions\FileProxyException;
use App\Services\Settings\SettingsService;
use App\Support\TelegramProxy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxy Telegram file bodies without storing them on the application server.
 */
class FileService
{
    private string $botToken;

    /** Resolve the active Telegram credential through runtime settings. */
    public function __construct()
    {
        $this->botToken = (string) app(SettingsService::class)->get('telegram.token');
    }

    /**
     * Prepare a full file or single byte range from cached Telegram metadata.
     *
     * @throws FileProxyException When metadata is unavailable or exceeds the limit.
     */
    public function streamFile(string $fileId, string $disposition = 'inline', ?string $range = null): StreamedResponse
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
        if (is_numeric($fileSize) && (float) $fileSize > $maxBytes) {
            throw new FileProxyException('file_too_large', 413);
        }
        $size = is_numeric($fileSize) && (float) $fileSize >= 0 ? (int) $fileSize : null;
        $range = $size !== null ? $this->singleRange($range) : null;
        $status = 200;
        $upstreamRange = null;

        $headers = [
            'Content-Type' => $this->getFileContentType($filePath),
            'Content-Disposition' => $disposition . '; filename="' . $this->safeFilename($filePath) . '"',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Accel-Buffering' => 'no',
        ];
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }
        if ($range !== null && $size !== null) {
            [$first, $last] = explode('-', substr($range, 6), 2);
            $start = $first === '' ? max(0, $size - (int) $last) : (int) $first;
            $end = $first === '' || $last === '' ? $size - 1 : min((int) $last, $size - 1);

            if ($start >= $size) {
                $status = 416;
                $headers['Content-Range'] = 'bytes */' . $size;
                $headers['Content-Length'] = '0';
            } else {
                $status = 206;
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
                $headers['Content-Length'] = (string) ($end - $start + 1);
                $upstreamRange = "bytes={$start}-{$end}";
            }
        }

        return response()->stream(function () use ($filePath, $status, $upstreamRange): void {
            if ($status === 416) {
                return;
            }

            $out = null;
            try {
                $out = $this->openOutputStream();
                $this->downloadTelegramFile($filePath, $out, $upstreamRange);
            } catch (\Throwable $e) {
                Log::channel('app')->warning('file_proxy_stream_failed', [
                    'error_code' => $e instanceof FileProxyException ? $e->errorCode : 'upstream_error',
                    'trace_id' => (string) Str::uuid(),
                ]);
            } finally {
                if (is_resource($out)) {
                    fclose($out);
                }
            }
        }, $status, $headers);
    }

    /** Stream a file as an attachment, optionally forwarding one byte range. */
    public function downloadFile(string $fileId, ?string $range = null): StreamedResponse
    {
        return $this->streamFile($fileId, 'attachment', $range);
    }

    /**
     * Cache successful Bot API metadata for ten minutes, scoped to the credential.
     *
     * @return array<string, mixed>
     *
     * @throws FileProxyException When Telegram cannot resolve the file.
     */
    public function getTelegramFile(string $fileId): array
    {
        if ($this->botToken === '') {
            throw new FileProxyException('upstream_unavailable', 502);
        }

        $cacheKey = 'telegram-file-metadata:' . hash('sha256', hash('sha256', $this->botToken) . '|' . $fileId);

        return Cache::remember($cacheKey, 600, function () use ($fileId): array {
            try {
                $url = "https://api.telegram.org/bot{$this->botToken}/getFile";
                $client = Http::connectTimeout((int) config('file_proxy.connect_timeout', 3))
                    ->timeout((int) config('file_proxy.timeout', 15))
                    ->withoutRedirecting();
                // Telescope records full request URLs, which contain the bot token.
                $response = Telescope::withoutRecording(fn (): Response => TelegramProxy::apply($client, $url)->get($url, [
                    'file_id' => $fileId,
                ]));
            } catch (ConnectionException) {
                throw new FileProxyException('upstream_timeout', 504);
            } catch (\Throwable) {
                throw new FileProxyException('upstream_error', 502);
            }

            $this->assertTelegramResponse($response);
            $json = $response->json();

            if (!is_array($json) || !array_key_exists('ok', $json)) {
                throw new FileProxyException('upstream_invalid_response', 502);
            }

            if ($json['ok'] !== true || !is_string($json['result']['file_path'] ?? null) || $json['result']['file_path'] === '') {
                throw new FileProxyException('file_not_found', 404);
            }

            return $json;
        });
    }

    /**
     * Let cURL write directly to the output sink, including through SOCKS proxies.
     *
     * @param resource $out The writable client output stream.
     *
     * @throws FileProxyException When the upstream cannot serve the file.
     */
    protected function downloadTelegramFile(string $filePath, $out, ?string $range = null): void
    {
        try {
            $url = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
            $client = Http::connectTimeout((int) config('file_proxy.connect_timeout', 3))
                ->timeout(0)
                ->withoutRedirecting()
                ->withOptions([
                    'sink' => $out,
                    'curl' => [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => (int) config('file_proxy.read_timeout', 30),
                    ],
                ]);
            if ($range !== null) {
                $client->withHeaders(['Range' => $range]);
            }
            $response = Telescope::withoutRecording(fn (): Response => TelegramProxy::apply($client, $url)->get($url));
        } catch (ConnectionException) {
            throw new FileProxyException('upstream_timeout', 504);
        } catch (\Throwable) {
            throw new FileProxyException('upstream_error', 502);
        }

        try {
            $this->assertTelegramResponse($response);
            if (!in_array($response->status(), [200, 206], true)) {
                throw new FileProxyException('upstream_invalid_response', 502);
            }
        } finally {
            $response->close();
        }
    }

    /**
     * Disable PHP buffering so the cURL sink reaches the client as data arrives.
     *
     * @return resource The client output, never a temporary file.
     *
     * @throws FileProxyException When client output cannot be opened.
     */
    protected function openOutputStream()
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new FileProxyException('upstream_error', 502);
        }

        return $out;
    }

    /** Ignore malformed, multipart, overflowing, reversed or empty suffix ranges. */
    private function singleRange(?string $range): ?string
    {
        if ($range === null || preg_match('/\Abytes=(?:[0-9]+-[0-9]*|-[0-9]+)\z/', $range) !== 1) {
            return null;
        }

        [$start, $end] = explode('-', substr($range, 6), 2);
        $maximum = (string) PHP_INT_MAX;
        foreach ([$start, $end] as $value) {
            $digits = ltrim($value, '0');
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                return null;
            }
        }
        if ($start === '') {
            return ltrim($end, '0') !== '' ? $range : null;
        }

        // Compare decimal strings without overflowing PHP integers.
        if ($end !== '') {
            $start = ltrim($start, '0');
            $end = ltrim($end, '0');
            if (strlen($start) > strlen($end) || (strlen($start) === strlen($end) && strcmp($start, $end) > 0)) {
                return null;
            }
        }

        return $range;
    }

    /** Map Telegram HTTP failures to the existing safe proxy error codes. */
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

    /** Strip unsafe characters from a filename used in Content-Disposition. */
    private function safeFilename(string $filePath): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filePath));

        return is_string($filename) && $filename !== '' ? $filename : 'telegram-file';
    }

    /** Resolve a safe content type from the Telegram file extension. */
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
