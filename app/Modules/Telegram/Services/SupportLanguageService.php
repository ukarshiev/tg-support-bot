<?php

namespace App\Modules\Telegram\Services;

class SupportLanguageService
{
    public const CALLBACK_PREFIX = 'select_language:';

    /**
     * @return array<string, array{name: string, native: string, greeting: string}>
     */
    public function all(): array
    {
        return (array) config('support_languages.languages', []);
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

    /**
     * Telegram inline keyboard: two language buttons per row.
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function keyboard(): array
    {
        $buttons = [];

        foreach ($this->all() as $code => $language) {
            $buttons[] = [
                'text' => $language['native'],
                'callback_data' => self::CALLBACK_PREFIX . $code,
            ];
        }

        return array_chunk($buttons, 2);
    }

    public function prompt(): string
    {
        return (string) config('support_languages.prompt', 'Выберите язык / Choose your language:');
    }

    public function greeting(string $code): string
    {
        return $this->find($code)['greeting'] ?? __('messages.start');
    }

    public function displayName(?string $code, ?string $fallback = null): string
    {
        if ($code !== null && ($language = $this->find($code)) !== null) {
            return $language['name'];
        }

        return $fallback ?: 'не выбран';
    }
}
