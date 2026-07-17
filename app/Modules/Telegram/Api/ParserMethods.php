<?php

namespace App\Modules\Telegram\Api;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\Exception as phpDocumentorException;

class ParserMethods
{
    private const CONNECT_TIMEOUT_SECONDS = 2;

    private const REQUEST_TIMEOUT_SECONDS = 8;

    private const UPLOAD_TIMEOUT_SECONDS = 15;

    /**
     * Send POST
     *
     * @param string       $urlQuery
     * @param array|string $queryParams
     * @param array        $queryHeading
     *
     * @return array
     */
    public static function postQuery(string $urlQuery, array|string $queryParams = [], array $queryHeading = []): array
    {
        try {
            $resultQuery = Http::withHeaders($queryHeading)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->post($urlQuery, $queryParams)
                ->json();

            if (empty($resultQuery)) {
                throw new \RuntimeException('Request caused an error');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            self::logTransportFailure('post', $e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => 'Request caused an error',
            ];
        }
    }

    /**
     * Send GET
     *
     * @param string       $urlQuery
     * @param array|string $queryParams
     * @param array        $queryHeading
     *
     * @return array
     */
    public static function getQuery(string $urlQuery, array|string $queryParams = [], array $queryHeading = []): array
    {
        try {
            if (!empty($queryParams)) {
                $urlQuery .= '?' . http_build_query($queryParams);
            }

            $resultQuery = Http::withHeaders($queryHeading)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->get($urlQuery)
                ->json();

            if (empty($resultQuery)) {
                throw new \RuntimeException('Request caused an error');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            self::logTransportFailure('get', $e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => 'Request caused an error',
            ];
        }
    }

    public static function attachQuery(string $urlQuery, array|string $queryParams = [], string $attachType = 'document'): array
    {
        try {
            if (empty($attachType)) {
                $attachType = 'document';
            }

            if (!empty($queryParams['uploaded_file_path'])) {
                // Path-based upload (used when UploadedFile cannot be serialized in a job)
                $tempPath = $queryParams['uploaded_file_path'];
                unset($queryParams['uploaded_file_path']);

                if (!file_exists($tempPath) || !is_readable($tempPath)) {
                    throw new phpDocumentorException('Temporary file does not exist or is not readable');
                }

                $safeName = Str::uuid() . '.' . pathinfo($tempPath, PATHINFO_EXTENSION);
                $fileHandle = fopen($tempPath, 'rb');

                if ($fileHandle === false) {
                    throw new phpDocumentorException('Cannot open temporary file');
                }

                $resultQuery = Http::attach($attachType, $fileHandle, $safeName)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::UPLOAD_TIMEOUT_SECONDS)
                    ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                    ->post($urlQuery, $queryParams)
                    ->json();

                @unlink($tempPath);
            } else {
                if (empty($queryParams['uploaded_file']) || !$queryParams['uploaded_file'] instanceof UploadedFile) {
                    throw new phpDocumentorException('File not provided!');
                }

                /** @var UploadedFile $attachData */
                $attachData = $queryParams['uploaded_file'];
                unset($queryParams['uploaded_file']);

                if ($attachData->getSize() > 50 * 1024 * 1024) {
                    throw new phpDocumentorException('File is too large for Telegram (max 50 MB)');
                }

                if ($attachData->getSize() === 0) {
                    throw new phpDocumentorException('File is empty and cannot be sent');
                }

                if (!$attachData->isValid()) {
                    throw new phpDocumentorException('File is invalid');
                }

                $tempPath = $attachData->getRealPath();

                if (!$tempPath || !file_exists($tempPath) || !is_readable($tempPath)) {
                    throw new phpDocumentorException('Temporary file does not exist or is not readable');
                }

                $extension = $attachData->getClientOriginalExtension();
                $safeName = Str::uuid() . ($extension ? '.' . $extension : '');

                $resultQuery = Http::attach(
                    $attachType,
                    fopen($tempPath, 'rb'),
                    $safeName
                )
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::UPLOAD_TIMEOUT_SECONDS)
                    ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                    ->post($urlQuery, $queryParams)
                    ->json();
            }

            if (empty($resultQuery)) {
                throw new \RuntimeException('Request caused an error');
            }

            return $resultQuery;
        } catch (\Throwable $e) {
            self::logTransportFailure('attachment', $e);
            return [
                'ok' => false,
                'response_code' => 500,
                'result' => self::safeAttachmentError($e),
            ];
        }
    }

    private static function safeAttachmentError(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        foreach (['File ', 'Temporary file ', 'Cannot open temporary file'] as $safePrefix) {
            if (str_starts_with($message, $safePrefix)) {
                return $message;
            }
        }

        return 'Request caused an error';
    }

    private static function logTransportFailure(string $operation, \Throwable $exception): void
    {
        $message = preg_replace(
            '~https://api\.telegram\.org/bot[^/\s]+~i',
            'https://api.telegram.org/bot[hidden]',
            $exception->getMessage(),
        ) ?? 'Telegram transport failed';

        Log::channel('app')->log(
            $exception->getCode() === 1 ? 'warning' : 'error',
            'Telegram transport failed',
            [
                'source' => 'telegram_transport_failed',
                'operation' => $operation,
                'error_class' => $exception::class,
                'error' => mb_substr($message, 0, 1000),
            ],
        );
    }
}
