<?php

namespace App\Support;

/**
 * Remove Telegram credentials and proxy authentication from diagnostic text.
 */
final class SecretMasker
{
    private const SANITIZE_FAILED = '[masked: sanitize failed]';

    /**
     * Mask known secrets, failing closed when a regular expression fails.
     */
    public static function mask(string $text): string
    {
        $patterns = [
            '~(api\.telegram\.org/(?:file/)?bot)[^/\s]+~i' => '$1[hidden]',
            '~bot[0-9]+:[A-Za-z0-9_-]+~' => 'bot[hidden]',
            '~[0-9]{6,}:[A-Za-z0-9_-]{30,}~' => '[hidden]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $text);

            if ($masked === null) {
                return self::SANITIZE_FAILED;
            }

            $text = $masked;
        }

        return TelegramProxy::maskCredentials($text);
    }
}
