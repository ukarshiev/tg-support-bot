<?php

namespace App\Modules\Translation\Services;

use App\Modules\Translation\Contracts\TranslationProvider;
use App\Modules\Translation\Providers\FakeTranslationProvider;
use App\Modules\Translation\Providers\GoogleTranslationProvider;
use App\Modules\Translation\Providers\OfflineHttpTranslationProvider;
use App\Modules\Translation\Providers\YandexTranslationProvider;

class TranslationProviderRegistry
{
    /**
     * @return array<string, class-string<TranslationProvider>>
     */
    public function providerClasses(): array
    {
        return [
            'fake' => FakeTranslationProvider::class,
            'yandex' => YandexTranslationProvider::class,
            'google' => GoogleTranslationProvider::class,
            'offline' => OfflineHttpTranslationProvider::class,
        ];
    }

    public function get(string $key): ?TranslationProvider
    {
        $class = $this->providerClasses()[$key] ?? null;

        return $class === null ? null : app($class);
    }
}
