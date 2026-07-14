<?php

namespace App\Modules\Telegram\Services;

use App\Models\AutoReply;
use App\Models\BotUser;
use App\Modules\Translation\Services\SupportLanguageSettings;
use App\Services\AutoReplies\SystemAutoReplyResolver;

class SupportLanguageService
{
    public const CALLBACK_PREFIX = 'select_language:';

    public const PAGE_CALLBACK_PREFIX = 'select_language_page:';

    public const PAGE_SIZE = 14;

    /**
     * @return array<string, array{name: string, native: string, greeting: string}>
     */
    public function all(): array
    {
        $languages = app(SupportLanguageSettings::class)->languages();
        $result = [];

        foreach ($languages as $code => $language) {
            if (!$language['enabled'] || !$language['show_on_start']) {
                continue;
            }

            $result[$code] = [
                'name' => $language['name'],
                'native' => $language['native'],
                'greeting' => (string) (config("support_languages.languages.{$code}.greeting") ?? __('messages.start')),
            ];
        }

        return $result;
    }

    /**
     * @return array{name: string, native: string, greeting: string}|null
     */
    public function find(?string $code): ?array
    {
        if ($code === null || $code === '') {
            return null;
        }

        return $this->all()[$code] ?? null;
    }

    public function isLanguageCallback(?string $callbackData): bool
    {
        return is_string($callbackData) && str_starts_with($callbackData, self::CALLBACK_PREFIX);
    }

    public function codeFromCallback(?string $callbackData): ?string
    {
        if (!$this->isLanguageCallback($callbackData)) {
            return null;
        }

        $code = substr((string) $callbackData, strlen(self::CALLBACK_PREFIX));

        return $this->find($code) === null ? null : $code;
    }

    public function isPageCallback(?string $callbackData): bool
    {
        return is_string($callbackData) && str_starts_with($callbackData, self::PAGE_CALLBACK_PREFIX);
    }

    public function pageFromCallback(?string $callbackData): int
    {
        if (!$this->isPageCallback($callbackData)) {
            return 1;
        }

        $page = (int) substr((string) $callbackData, strlen(self::PAGE_CALLBACK_PREFIX));

        return $this->normalizePage($page);
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil(count($this->all()) / self::PAGE_SIZE));
    }

    /**
     * Telegram inline keyboard: two language buttons per row.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function keyboard(int $page = 1): array
    {
        $buttons = [];
        $page = $this->normalizePage($page);
        $languages = array_slice($this->all(), ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);

        foreach ($languages as $code => $language) {
            $buttons[] = [
                'text' => $language['native'],
                'callback_data' => self::CALLBACK_PREFIX . $code,
            ];
        }

        $keyboard = array_chunk($buttons, 2);
        $navigation = [];

        if ($page > 1) {
            $navigation[] = [
                'text' => '◀',
                'callback_data' => self::PAGE_CALLBACK_PREFIX . ($page - 1),
            ];
        }

        if ($page < $this->totalPages()) {
            $navigation[] = [
                'text' => '▶',
                'callback_data' => self::PAGE_CALLBACK_PREFIX . ($page + 1),
            ];
        }

        if ($navigation !== []) {
            $position = $page > 1 ? 1 : 0;
            array_splice($navigation, $position, 0, [[
                'text' => $page . '/' . $this->totalPages(),
                'callback_data' => self::PAGE_CALLBACK_PREFIX . $page,
            ]]);
            $keyboard[] = $navigation;
        }

        return $keyboard;
    }

    public function prompt(int $page = 1, ?string $locale = null): string
    {
        return (string) config('support_languages.interface.choose_language.' . $locale, 'Choose language');
    }

    public function isSelectorText(?string $text): bool
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }

        $titles = array_values((array) config('support_languages.interface.choose_language', []));
        $titles[] = (string) config('support_languages.prompt', 'Выберите язык / Choose your language:');

        foreach (array_unique(array_filter($titles)) as $title) {
            if ($text === $title || str_starts_with($text, $title . "\n")) {
                return true;
            }
        }

        return false;
    }

    public function greeting(string $code, ?BotUser $botUser = null): ?string
    {
        return app(SystemAutoReplyResolver::class)->resolve(
            AutoReply::TYPE_WELCOME,
            $botUser,
            $code,
        );
    }

    public function displayName(?string $code, ?string $fallback = null): string
    {
        if ($code !== null && ($language = $this->find($code)) !== null) {
            return $language['name'];
        }

        return $fallback ?: 'не выбран';
    }

    private function normalizePage(int $page): int
    {
        return min(max(1, $page), $this->totalPages());
    }
}
