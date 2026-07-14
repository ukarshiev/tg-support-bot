<?php

namespace App\Modules\Max\Actions;

use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MaxBotApi\Config;
use MaxBotApi\MaxClient;

class UploadFileMax
{
    /**
     * Download a file from Telegram and upload it to Max.
     * Returns the attachment token for use in a message, or null on failure.
     *
     * Token sources differ by upload type:
     *   - image : CDN returns {"photos": {"<key>": {"token": "..."}}}
     *   - file  : CDN returns {"token": "..."}
     *   - video / audio: token is returned by the /uploads API call (getUploadUrl)
     *
     * @param string $telegramFileUrl Full Telegram file URL to download from.
     * @param string $filename        Original filename.
     * @param string $type            Max upload type: 'image', 'file', 'video', 'audio'.
     *
     * @return string|null
     */
    public function execute(string $telegramFileUrl, string $filename, string $type): ?string
    {
        try {
            $fileResponse = Http::get($telegramFileUrl);
            if ($fileResponse->failed()) {
                throw new \Exception("Failed to download from Telegram: HTTP {$fileResponse->status()}");
            }

            return $this->uploadContents($fileResponse->body(), $filename, $type);
        } catch (\Throwable $e) {
            Log::channel('app')->error('UploadFileMax: download failed', [
                'type' => $type,
                'error_type' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Upload raw file bytes to Max's CDN and return the attachment token.
     *
     * For files already available locally (e.g. a manager reply attachment)
     * rather than fetched from a Telegram URL. Token sources differ by upload
     * type — see the execute() docblock.
     *
     * @param string $contents Raw file bytes.
     * @param string $filename Original filename.
     * @param string $type     Max upload type: 'image', 'file', 'video', 'audio'.
     *
     * @return string|null
     */
    public function uploadContents(string $contents, string $filename, string $type): ?string
    {
        try {
            $client = new MaxClient(new Config(
                token: (string) app(SettingsService::class)->get('max.token'),
            ));

            $uploadResult = $client->uploads->getUploadUrl($type);

            Log::channel('app')->info('UploadFileMax: uploading to CDN', [
                'type' => $type,
                'size' => strlen($contents),
            ]);

            $cdnResponse = Http::attach('data', $contents, $filename)
                ->post($uploadResult->url);

            if ($cdnResponse->failed()) {
                throw new \Exception("CDN upload failed: HTTP {$cdnResponse->status()}");
            }

            $cdnData = $cdnResponse->json() ?? [];

            Log::channel('app')->info('UploadFileMax: CDN response received', [
                'type' => $type,
                'status' => $cdnResponse->status(),
            ]);

            $token = $uploadResult->token
                ?? $cdnData['token']
                ?? (isset($cdnData['photos']) ? array_values($cdnData['photos'])[0]['token'] ?? null : null)
                ?? null;

            if ($token === null) {
                throw new \RuntimeException('No token received from CDN response.');
            }

            Log::channel('app')->info('UploadFileMax: upload succeeded', ['type' => $type]);

            return $token;
        } catch (\Throwable $e) {
            Log::channel('app')->error('UploadFileMax: upload failed', [
                'type' => $type,
                'error_type' => $e::class,
            ]);

            return null;
        }
    }
}
