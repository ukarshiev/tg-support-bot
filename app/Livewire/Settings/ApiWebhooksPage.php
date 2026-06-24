<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\ExternalSource;
use App\Models\User;
use App\Modules\External\DTOs\ExternalSourceDto;
use App\Modules\External\Services\Source\ExternalSourceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "API и вебхуки" settings page — list of External Source cards.
 *
 * Shows a card per External Source (name, token status, webhook status) with
 * a link to the per-source edit page. The "Добавить источник" button creates a
 * source with a unique placeholder name and an auto-issued bearer token, then
 * redirects to the edit page where the name is set.
 *
 * Token regeneration, webhook URL editing, and per-source config are handled
 * by ApiWebhookSourcePage (GET /admin/settings/api-webhooks/{source}).
 *
 * Route:  GET /admin/settings/api-webhooks
 * Name:   admin.settings.api-webhooks
 * Access: authenticated admin only (isAdmin() check in mount()).
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 */
#[Layout('layouts.admin-settings')]
class ApiWebhooksPage extends Component
{
    /**
     * Loaded external sources (collection as array).
     *
     * @var array<int, ExternalSource>
     */
    public array $sources = [];

    /**
     * Error shown when source creation fails.
     */
    public ?string $addError = null;

    /**
     * Mount: load sources and redirect non-admins to the settings home.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->isAdmin()) {
            $this->redirectRoute('admin.settings.general');

            return;
        }

        $this->loadSources();
    }

    /**
     * Reload sources from DB.
     */
    public function loadSources(): void
    {
        $sources = ExternalSource::with('accessTokens')->get();

        $this->sources = $sources->keyBy('id')->all();
    }

    /**
     * Deterministic avatar background colour for a source.
     * Derived from the source name — produces one of 8 palette colours.
     *
     * @param ExternalSource $source
     *
     * @return string Hex colour string.
     */
    public function avatarColor(ExternalSource $source): string
    {
        $palette = [
            '#5B6ABF', '#E85D75', '#34C759', '#F5A623',
            '#06B6D4', '#10B981', '#8B5CF6', '#EF4444',
        ];

        return $palette[abs(crc32($source->name)) % 8];
    }

    /**
     * Two-letter uppercase initials from the source name.
     *
     * @param ExternalSource $source
     *
     * @return string
     */
    public function avatarInitials(ExternalSource $source): string
    {
        $name = trim($source->name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name);

        if (is_array($parts) && count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    /**
     * Create a new External Source and redirect to its edit page.
     *
     * The source is created with a unique placeholder name (set properly on the
     * edit page) and an auto-issued bearer token.
     *
     * @param ExternalSourceService $service
     */
    public function addSource(ExternalSourceService $service): void
    {
        $this->addError = null;

        try {
            $source = $service->create(new ExternalSourceDto(
                id: null,
                name: $this->placeholderName(),
                webhook_url: null,
                created_at: null,
                updated_at: null,
            ));
        } catch (\Throwable $e) {
            $this->addError = 'Не удалось создать источник.';

            return;
        }

        $this->redirectRoute('admin.settings.api-webhooks.source', ['source' => $source->id]);
    }

    /**
     * Delete an External Source (its access tokens are removed via FK cascade).
     *
     * @param int $id
     */
    public function deleteSource(int $id): void
    {
        ExternalSource::whereKey($id)->delete();

        $this->loadSources();
    }

    /**
     * Build a unique placeholder name for a newly created source.
     *
     * @return string
     */
    private function placeholderName(): string
    {
        $base = 'Новый источник';
        $name = $base;
        $i = 1;

        while (ExternalSource::where('name', $name)->exists()) {
            $i++;
            $name = "{$base} {$i}";
        }

        return $name;
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.api-webhooks-page');
    }
}
