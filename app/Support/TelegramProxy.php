<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;

final class TelegramProxy
{
    public static function apply(PendingRequest $request, string $url): PendingRequest
    {
        $proxy = trim((string) config('traffic_source.telegram.proxy'));

        if ($proxy === '' || ! self::isTelegramApiUrl($url)) {
            return $request;
        }

        return $request->withOptions(['proxy' => $proxy]);
    }

    public static function maskCredentials(string $message): string
    {
        return preg_replace(
            '~\b((?:https?|socks5h)://)[^/@\s]+@~i',
            '$1[hidden]@',
            $message,
        ) ?? $message;
    }

    private static function isTelegramApiUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === 'api.telegram.org';
    }
}
