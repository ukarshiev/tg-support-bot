<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\TranslationUsageLog;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\SupportLanguageSettings;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin-settings')]
class LanguageSettingsPage extends Component
{
    private const LANGUAGES_PER_PAGE = 14;

    public string $activeTab = 'languages';

    /** @var array<int, array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}> */
    public array $languages = [];

    public int $languagePage = 1;

    /** @var array<int, string> */
    public array $providerOrder = ['yandex', 'google', 'offline'];

    public bool $allowExternal = false;

    public string $yandexApiKey = '';

    public string $yandexFolderId = '';

    public string $googleApiKey = '';

    public string $offlineEndpoint = '';

    public string $testText = 'Добрый день! Чем я могу вам помочь?';

    public string $testTargetLocale = 'en';

    public string $testProvider = 'yandex';

    public ?string $testResult = null;

    public ?string $testError = null;

    public bool $saved = false;

    public function mount(SupportLanguageSettings $languageSettings, SettingsService $settings): void
    {
        $this->languages = array_values($languageSettings->languages());
        $this->providerOrder = $this->normalizeProviderOrder($settings->get('translation.provider_order'));
        $this->allowExternal = (bool) $settings->get('translation.allow_external', false);
        $this->yandexApiKey = (string) ($settings->get('translation.yandex_api_key') ?? '');
        $this->yandexFolderId = (string) ($settings->get('translation.yandex_folder_id') ?? '');
        $this->googleApiKey = (string) ($settings->get('translation.google_api_key') ?? '');
        $this->offlineEndpoint = (string) ($settings->get('translation.offline_endpoint') ?? '');
        $requestedTab = request()->query('tab');
        if ($requestedTab === 'providers') {
            $this->activeTab = 'providers';
        }

        $this->testTargetLocale = $this->languages[1]['code'] ?? 'en';
        $this->testProvider = $this->providerOrder[0] ?? 'yandex';
    }

    public function setLanguagePage(int $page): void
    {
        $this->languagePage = max(1, min($page, $this->languagePagesCount()));
    }

    public function saveLanguages(SupportLanguageSettings $languageSettings): void
    {
        $this->saved = false;
        $languageSettings->save($this->languages);
        $this->saved = true;
    }

    public function saveProviders(?string $enteredYandexApiKey = null, ?string $enteredGoogleApiKey = null): void
    {
        $settings = app(SettingsService::class);

        $this->saved = false;
        $this->applyEnteredProviderKeys($enteredYandexApiKey, $enteredGoogleApiKey);

        $settings->set('translation.provider_order', $this->normalizeProviderOrder($this->providerOrder));
        $settings->set('translation.allow_external', $this->allowExternal);
        $settings->set('translation.yandex_folder_id', trim($this->yandexFolderId));
        $settings->set('translation.offline_endpoint', trim($this->offlineEndpoint));

        $this->persistEnteredProviderKeys($settings);
        $this->saved = true;
    }

    public function testTranslation(?string $enteredYandexApiKey = null, ?string $enteredGoogleApiKey = null): void
    {
        $settings = app(SettingsService::class);

        $this->testResult = null;
        $this->testError = null;
        $this->applyEnteredProviderKeys($enteredYandexApiKey, $enteredGoogleApiKey);

        $this->persistEnteredProviderKeys($settings);

        $provider = $this->normalizeTestProvider($this->testProvider);
        $this->testProvider = $provider;

        $translation = app(TranslationService::class);
        $result = $translation->translateWithProvider($provider, new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: $this->testTargetLocale,
            text: $this->testText,
            purpose: 'settings_test',
        ));

        if ($result->success) {
            $this->testResult = '[' . $result->provider . '] ' . $result->text;
            return;
        }

        $this->testError = $result->errorMessage ?? $result->errorCode ?? 'Перевод не выполнен.';
    }

    public function moveProviderUp(int $index): void
    {
        if ($index <= 0 || !isset($this->providerOrder[$index])) {
            return;
        }

        [$this->providerOrder[$index - 1], $this->providerOrder[$index]] = [$this->providerOrder[$index], $this->providerOrder[$index - 1]];
    }

    public function moveProviderDown(int $index): void
    {
        if (!isset($this->providerOrder[$index], $this->providerOrder[$index + 1])) {
            return;
        }

        [$this->providerOrder[$index + 1], $this->providerOrder[$index]] = [$this->providerOrder[$index], $this->providerOrder[$index + 1]];
    }

    /**
     * @return array<string, array{today: int, month: int, last_success: string|null, last_error: string|null}>
     */
    public function providerStats(): array
    {
        $stats = [];
        $today = Carbon::today();
        $month = Carbon::now()->startOfMonth();

        foreach (['yandex', 'google', 'offline', 'fake'] as $provider) {
            $stats[$provider] = [
                'today' => (int) TranslationUsageLog::where('provider', $provider)
                    ->where('created_at', '>=', $today)
                    ->sum('characters'),
                'month' => (int) TranslationUsageLog::where('provider', $provider)
                    ->where('created_at', '>=', $month)
                    ->sum('characters'),
                'last_success' => optional(TranslationUsageLog::where('provider', $provider)
                    ->where('success', true)
                    ->latest()
                    ->first()?->created_at)->format('d.m.Y H:i'),
                'last_error' => TranslationUsageLog::where('provider', $provider)
                    ->where('success', false)
                    ->latest()
                    ->value('error_message'),
            ];
        }

        return $stats;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.language-settings-page', [
            'providerStats' => $this->providerStats(),
            'languagePagesCount' => $this->languagePagesCount(),
            'paginatedLanguages' => $this->paginatedLanguages(),
        ]);
    }

    /**
     * @return array<int, array{index: int, language: array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}}>
     */
    public function paginatedLanguages(): array
    {
        $this->languagePage = max(1, min($this->languagePage, $this->languagePagesCount()));
        $offset = ($this->languagePage - 1) * self::LANGUAGES_PER_PAGE;

        return collect($this->languages)
            ->slice($offset, self::LANGUAGES_PER_PAGE)
            ->values()
            ->map(fn (array $language, int $pageIndex): array => [
                'index' => $offset + $pageIndex,
                'language' => $language,
            ])
            ->all();
    }

    public function languagePagesCount(): int
    {
        return max(1, (int) ceil(count($this->languages) / self::LANGUAGES_PER_PAGE));
    }

    private function applyEnteredProviderKeys(?string $enteredYandexApiKey, ?string $enteredGoogleApiKey): void
    {
        if ($enteredYandexApiKey !== null) {
            $this->yandexApiKey = $enteredYandexApiKey;
        }

        if ($enteredGoogleApiKey !== null) {
            $this->googleApiKey = $enteredGoogleApiKey;
        }
    }

    private function persistEnteredProviderKeys(SettingsService $settings): void
    {
        $yandexApiKey = trim($this->yandexApiKey);
        if ($yandexApiKey !== '') {
            $settings->set('translation.yandex_api_key', $yandexApiKey);
            $this->yandexApiKey = $yandexApiKey;
        }

        $googleApiKey = trim($this->googleApiKey);
        if ($googleApiKey !== '') {
            $settings->set('translation.google_api_key', $googleApiKey);
            $this->googleApiKey = $googleApiKey;
        }
    }

    private function normalizeTestProvider(string $provider): string
    {
        $allowed = ['yandex', 'google', 'offline', 'fake'];

        if (in_array($provider, $allowed, true)) {
            return $provider;
        }

        return $this->providerOrder[0] ?? 'yandex';
    }

    /**
     * @param mixed $value
     *
     * @return array<int, string>
     */
    private function normalizeProviderOrder(mixed $value): array
    {
        $allowed = ['yandex', 'google', 'offline', 'fake'];
        $order = is_array($value) ? $value : $this->providerOrder;
        $order = array_values(array_unique(array_filter($order, static fn ($item): bool => in_array($item, $allowed, true))));

        return $order === [] ? ['yandex', 'google', 'offline'] : $order;
    }
}
