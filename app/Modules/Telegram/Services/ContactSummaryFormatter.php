<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Models\BotUser;

class ContactSummaryFormatter
{
    public function __construct(
        private readonly SupportLanguageService $languages,
    ) {
    }

    /**
     * @param array<string, mixed> $chatData
     *
     * @return array<int, array{label: string, value: string, url?: string|null}>
     */
    public function rows(BotUser $botUser, array $chatData = []): array
    {
        $username = $botUser->username ?: ($chatData['username'] ?? null);
        $displayName = $botUser->display_name
            ?: trim((string) ($chatData['first_name'] ?? '') . ' ' . (string) ($chatData['last_name'] ?? ''));
        $languageName = $this->languages->displayName(
            $botUser->preferred_language_code,
            $botUser->preferred_language_name
        );
        $telegramLanguageCode = $chatData['language_code'] ?? null;
        $profileLink = $this->profileLink($botUser, is_string($username) ? $username : null);

        return [
            ['label' => 'Источник', 'value' => (string) $botUser->platform],
            ['label' => 'ID', 'value' => (string) $botUser->chat_id],
            ['label' => 'Имя', 'value' => trim((string) $displayName) !== '' ? (string) $displayName : 'не указано'],
            ['label' => 'Пользователь', 'value' => $username ? (string) $username : 'не указан'],
            ['label' => 'Ссылка', 'value' => $profileLink ?? 'не доступна', 'url' => $profileLink],
            ['label' => 'Выбранный язык', 'value' => $languageName],
            ['label' => 'Telegram language_code', 'value' => $telegramLanguageCode ? (string) $telegramLanguageCode : 'не доступен'],
            ['label' => 'Телефон', 'value' => 'не передан'],
            ['label' => 'Регион', 'value' => 'не определён'],
            ['label' => 'Первое обращение', 'value' => optional($botUser->created_at)->format('d.m.Y H:i') ?: 'неизвестно'],
            ['label' => 'Последняя активность', 'value' => optional($botUser->updated_at)->format('d.m.Y H:i') ?: 'неизвестно'],
        ];
    }

    /**
     * @param array<string, mixed> $chatData
     */
    public function toTelegramHtml(BotUser $botUser, array $chatData = []): string
    {
        $text = "<b>КОНТАКТНАЯ ИНФОРМАЦИЯ</b>\n";

        foreach ($this->rows($botUser, $chatData) as $row) {
            $value = $row['label'] === 'ID'
                ? '<code>' . e($row['value']) . '</code>'
                : e($row['value']);

            if ($row['label'] === 'Пользователь' && $row['value'] !== 'не указан') {
                $value = '<code>' . e($row['value']) . '</code>';
            }

            $text .= e($row['label']) . ': ' . $value . "\n";
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $chatData
     */
    public function toPlainText(BotUser $botUser, array $chatData = []): string
    {
        $text = "КОНТАКТНАЯ ИНФОРМАЦИЯ\n";

        foreach ($this->rows($botUser, $chatData) as $row) {
            $text .= $row['label'] . ': ' . $row['value'] . "\n";
        }

        return trim($text);
    }

    private function profileLink(BotUser $botUser, ?string $username): ?string
    {
        if ($botUser->platform === 'telegram' && $username) {
            return 'https://telegram.me/' . ltrim($username, '@');
        }

        if ($botUser->platform === 'vk' && ctype_digit(trim((string) $botUser->chat_id))) {
            return 'https://vk.com/id' . trim((string) $botUser->chat_id);
        }

        return null;
    }
}
