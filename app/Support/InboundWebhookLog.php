<?php

namespace App\Support;

final class InboundWebhookLog
{
    /**
     * Формирует безопасный контекст без текста сообщений, вложений и секретов.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, bool|int|string|null>
     */
    public static function summarize(string $platform, array $payload): array
    {
        $message = match ($platform) {
            'telegram' => self::telegramMessage($payload),
            'vk' => self::vkMessage($payload),
            'max' => self::maxMessage($payload),
            default => [],
        };

        $subject = self::subject($platform, $message, $payload);
        $attachments = $message['attachments'] ?? [];

        return [
            'source' => $platform . '_request',
            'event_id' => self::eventId($platform, $payload, $message),
            'event_type' => self::eventType($platform, $payload),
            'subject_hash' => $subject === null ? null : hash_hmac('sha256', $subject, self::hashKey()),
            'has_text' => self::hasText($message),
            'attachments_count' => is_array($attachments) ? count($attachments) : 0,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function telegramMessage(array $payload): array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post', 'callback_query'] as $key) {
            $candidate = $payload[$key] ?? null;
            if ($key === 'callback_query' && is_array($candidate)) {
                $candidate = $candidate['message'] ?? [];
            }
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private static function vkMessage(array $payload): array
    {
        $object = $payload['object'] ?? [];
        if (!is_array($object)) {
            return [];
        }

        $message = $object['message'] ?? $object;

        return is_array($message) ? $message : [];
    }

    /** @param array<string, mixed> $payload */
    private static function maxMessage(array $payload): array
    {
        $message = $payload['message'] ?? [];
        if (!is_array($message)) {
            return [];
        }

        $body = $message['body'] ?? [];
        if (is_array($body)) {
            $message['text'] = $body['text'] ?? null;
            $message['attachments'] = $body['attachments'] ?? [];
        }

        return $message;
    }

    /** @param array<string, mixed> $message */
    private static function hasText(array $message): bool
    {
        foreach (['text', 'caption'] as $field) {
            if (is_string($message[$field] ?? null) && trim($message[$field]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $payload
     */
    private static function subject(string $platform, array $message, array $payload): ?string
    {
        $value = match ($platform) {
            'telegram' => $message['chat']['id'] ?? $message['from']['id'] ?? null,
            'vk' => $message['peer_id'] ?? $message['from_id'] ?? null,
            'max' => $message['recipient']['chat_id'] ?? $message['sender']['user_id'] ?? null,
            default => null,
        };

        if ($value === null) {
            $value = $payload['chat_id'] ?? null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $message
     */
    private static function eventId(string $platform, array $payload, array $message): string|int|null
    {
        $value = match ($platform) {
            'telegram' => $payload['update_id'] ?? $message['message_id'] ?? null,
            'vk' => $payload['event_id'] ?? $message['conversation_message_id'] ?? $message['id'] ?? null,
            'max' => $payload['update_id'] ?? $message['body']['mid'] ?? $message['id'] ?? null,
            default => null,
        };

        return is_int($value) || is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    private static function eventType(string $platform, array $payload): ?string
    {
        $value = match ($platform) {
            'telegram' => collect(['message', 'edited_message', 'callback_query', 'channel_post'])
                ->first(fn (string $key): bool => array_key_exists($key, $payload)),
            'vk', 'max' => $payload['type'] ?? $payload['update_type'] ?? null,
            default => null,
        };

        return is_string($value) ? $value : null;
    }

    private static function hashKey(): string
    {
        $key = (string) config('app.key', 'tg-support-bot');

        return $key !== '' ? $key : 'tg-support-bot';
    }
}
