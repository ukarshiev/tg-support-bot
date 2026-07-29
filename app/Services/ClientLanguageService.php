<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BotUser;
use App\Modules\Translation\Services\SupportLanguageSettings;
use InvalidArgumentException;

final class ClientLanguageService
{
    public function __construct(
        private readonly SupportLanguageSettings $settings,
    ) {
    }

    public function select(BotUser $botUser, ?string $code): BotUser
    {
        $code = $this->normalize($code);

        if ($code === null) {
            $botUser->update([
                'preferred_language_code' => null,
                'preferred_language_name' => null,
                'preferred_language_selected_at' => null,
            ]);

            return $botUser->fresh();
        }

        $language = collect($this->settings->enabledLanguages())
            ->firstWhere('code', $code);

        if (!is_array($language)) {
            throw new InvalidArgumentException("Unsupported client language: {$code}");
        }

        $botUser->update([
            'preferred_language_code' => $code,
            'preferred_language_name' => (string) $language['name'],
            'preferred_language_selected_at' => now(),
        ]);

        return $botUser->fresh();
    }

    public function code(?BotUser $botUser): ?string
    {
        return $this->normalize($botUser?->preferred_language_code);
    }

    public function requiresTranslation(?BotUser $botUser): bool
    {
        $code = $this->code($botUser);

        return $code !== null && $code !== 'ru';
    }

    private function normalize(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));

        return $code !== '' ? str_replace('_', '-', $code) : null;
    }
}
