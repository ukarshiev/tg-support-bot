<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\ExternalSource;
use App\Models\User;
use App\Modules\External\Services\Source\ExternalSourceTokensService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-source External Source configuration page.
 *
 * Handles token regeneration and webhook URL editing for a single External Source.
 * Mirrors the IntegrationChannelPage UX (top breadcrumb bar + two-column body).
 *
 * Route:  GET /admin/settings/api-webhooks/{source}
 * Name:   admin.settings.api-webhooks.source
 * Access: authenticated admin only (isAdmin() check in mount()).
 * Layout: custom dark-sidebar admin layout (layouts.admin-settings).
 */
#[Layout('layouts.admin-settings')]
class ApiWebhookSourcePage extends Component
{
    /** @var int The External Source ID */
    public int $sourceId = 0;

    /** @var string The External Source name */
    public string $sourceName = '';

    /** @var bool Whether an access token record exists */
    public bool $hasToken = false;

    /** @var string|null Last 6 chars of the active token (for masked display) */
    public ?string $tokenLast6 = null;

    /** @var string|null Raw token value for clipboard copy (only when token present) */
    public ?string $copyToken = null;

    /** @var string Webhook URL being edited */
    public string $webhookUrl = '';

    /** @var string Allowed request IPs, one per line (textarea-bound) */
    public string $allowedIps = '';

    /** @var string|null Validation error for the allowed-IPs field */
    public ?string $allowedIpsError = null;

    /** @var string|null One-time reveal: raw new token after regeneration */
    public ?string $newToken = null;

    /** @var string|null Error message for token operations */
    public ?string $tokenError = null;

    /** @var string|null Validation error for webhook URL */
    public ?string $webhookError = null;

    /** @var string|null Validation error for the source name */
    public ?string $nameError = null;

    /** @var bool Webhook URL was saved successfully in this request */
    public bool $saved = false;

    /**
     * Mount the component with the source ID from the route.
     *
     * @param int $source
     */
    public function mount(int $source): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->isAdmin()) {
            $this->redirectRoute('admin.settings.general');

            return;
        }

        $externalSource = ExternalSource::find($source);

        if (! $externalSource) {
            $this->redirectRoute('admin.settings.api-webhooks');

            return;
        }

        $this->sourceId = $externalSource->id;
        $this->sourceName = $externalSource->name;
        $this->webhookUrl = (string) ($externalSource->webhook_url ?? '');
        $this->allowedIps = implode("\n", $externalSource->allowed_ips ?? []);

        $this->loadToken();
    }

    /**
     * Regenerate the bearer token for this External Source.
     *
     * The raw token is stored in $newToken for a one-time reveal only.
     * It is never logged.
     *
     * @param ExternalSourceTokensService $svc
     */
    public function regenerateToken(ExternalSourceTokensService $svc): void
    {
        $this->tokenError = null;
        $this->newToken = null;

        try {
            $this->newToken = $svc->setAccessToken($this->sourceId);
        } catch (\Throwable $e) {
            $this->tokenError = $e->getMessage();
        }

        $this->loadToken();
    }

    /**
     * Dismiss the one-time token reveal banner.
     */
    public function dismissNewToken(): void
    {
        $this->newToken = null;
    }

    /**
     * Save the source name, webhook URL, and allowed-IPs allowlist.
     *
     * The name is required, max 255 chars, and unique across sources (excluding
     * this one). Empty webhook URL is accepted (clears the stored URL); a
     * non-empty value must pass FILTER_VALIDATE_URL. The allowed-IPs textarea
     * (one entry per line) is parsed into a deduplicated list; every entry must
     * be a valid IP. An empty allowlist means requests are allowed from any IP.
     */
    public function saveWebhookUrl(): void
    {
        $this->nameError = null;
        $this->webhookError = null;
        $this->allowedIpsError = null;
        $this->saved = false;

        $name = trim($this->sourceName);

        if ($name === '') {
            $this->nameError = 'Введите название источника.';

            return;
        }

        if (mb_strlen($name) > 255) {
            $this->nameError = 'Название не должно превышать 255 символов.';

            return;
        }

        if (ExternalSource::where('name', $name)->where('id', '!=', $this->sourceId)->exists()) {
            $this->nameError = 'Источник с таким названием уже существует.';

            return;
        }

        $url = trim($this->webhookUrl);

        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->webhookError = 'Введите корректный URL (например: https://example.com/webhook).';

            return;
        }

        $ips = $this->parseAllowedIps();

        if ($ips === null) {
            return;
        }

        $externalSource = ExternalSource::find($this->sourceId);

        if (! $externalSource) {
            $this->webhookError = 'Источник не найден.';

            return;
        }

        $externalSource->update([
            'name' => $name,
            'webhook_url' => $url !== '' ? $url : null,
            'allowed_ips' => ! empty($ips) ? $ips : null,
        ]);

        $this->sourceName = $name;
        $this->saved = true;
    }

    /**
     * Parse and validate the allowed-IPs textarea.
     *
     * Returns a deduplicated list of valid IPs, or null when a line is not a
     * valid IP (in which case $allowedIpsError is set).
     *
     * @return array<int, string>|null
     */
    private function parseAllowedIps(): ?array
    {
        $lines = preg_split('/[\r\n,]+/', $this->allowedIps) ?: [];

        $ips = [];

        foreach ($lines as $line) {
            $ip = trim($line);

            if ($ip === '') {
                continue;
            }

            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                $this->allowedIpsError = "Некорректный IP-адрес: {$ip}";

                return null;
            }

            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.api-webhook-source-page');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Reload token state from DB.
     */
    private function loadToken(): void
    {
        $externalSource = ExternalSource::with('accessTokens')->find($this->sourceId);

        if (! $externalSource) {
            $this->hasToken = false;
            $this->tokenLast6 = null;
            $this->copyToken = null;

            return;
        }

        /** @var \App\Models\ExternalSourceAccessTokens|null $token */
        $token = $externalSource->accessTokens->first();

        if ($token) {
            $this->hasToken = true;
            $this->tokenLast6 = substr($token->token, -6);
            $this->copyToken = $token->token;
        } else {
            $this->hasToken = false;
            $this->tokenLast6 = null;
            $this->copyToken = null;
        }
    }
}
