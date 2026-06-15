<?php

namespace App\Modules\Admin;

use App\Livewire\Chat\ConversationPage;
use App\Livewire\Settings\AiAssistantPage;
use App\Livewire\Settings\AiProviderAccessPage;
use App\Livewire\Settings\ApiWebhookSourcePage;
use App\Livewire\Settings\ApiWebhooksPage;
use App\Livewire\Settings\AutoRepliesPage;
use App\Livewire\Settings\AutoReplyFormPage;
use App\Livewire\Settings\GeneralSettingsPage;
use App\Livewire\Settings\IntegrationChannelPage;
use App\Livewire\Settings\IntegrationsListPage;
use App\Livewire\Settings\TeamMemberCreatePage;
use App\Livewire\Settings\TeamMemberEditPage;
use App\Livewire\Settings\TeamPage;
use App\Modules\Admin\Controllers\ChatAttachmentController;
use App\Modules\Admin\Controllers\PwaController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AdminServiceProvider extends ServiceProvider
{
    /**
     * Зарегистрировать Admin-модуль.
     * Filament-роуты регистрируются через AdminPanelProvider.
     * Custom Livewire settings routes are registered here to avoid collision
     * with Filament's own route set (Filament owns /admin/* but does NOT
     * register /admin/settings/*).
     */
    public function boot(): void
    {
        // ── Chat workspace route ───────────────────────────────────────────────
        // Full-screen standalone Livewire route at /admin/chats.
        // This is the primary manager entry point when MANAGER_INTERFACE=admin_panel.
        // Middleware mirrors the settings routes: web session + Filament Authenticate.
        Route::middleware(['web', Authenticate::class])
            ->get('/admin/chats', ConversationPage::class)
            ->name('admin.chats');

        // PWA manifest + service worker — public (the browser fetches them
        // outside the session); served under /admin/ so the SW scope is /admin/.
        Route::middleware(['web'])
            ->get('/admin/manifest.webmanifest', [PwaController::class, 'manifest'])
            ->name('admin.pwa.manifest');
        Route::middleware(['web'])
            ->get('/admin/sw.js', [PwaController::class, 'serviceWorker'])
            ->name('admin.pwa.sw');

        // Streams locally-stored manager-reply attachments (e.g. MAX files) to the
        // chat thread — auth-gated, no public-disk/symlink dependency.
        Route::middleware(['web', Authenticate::class])
            ->get('/admin/chat-attachments/{attachment}', [ChatAttachmentController::class, 'show'])
            ->name('admin.chat-attachment')
            ->where('attachment', '[0-9]+');

        // Custom Livewire Settings routes.
        // Prefix: /admin/settings — verified not claimed by Filament's panel.
        // Middleware: 'web' session stack + Filament's Authenticate guard so
        // unauthenticated visitors are redirected to /admin/login.
        Route::middleware(['web', Authenticate::class])
            ->prefix('admin/settings')
            ->name('admin.settings.')
            ->group(function (): void {
                Route::get('/general', GeneralSettingsPage::class)
                    ->name('general');

                // Integrations index — renders the mobile card-list page.
                // Desktop users are redirected client-side (window.innerWidth check)
                // by a script embedded in the list page view, so we avoid UA sniffing
                // and keep the Livewire route simple.
                // The list page is also directly accessible via /integrations/list.
                Route::get('/integrations', IntegrationsListPage::class)
                    ->name('integrations');

                Route::get('/integrations/list', IntegrationsListPage::class)
                    ->name('integrations.list');

                Route::get('/integrations/{channel}', IntegrationChannelPage::class)
                    ->name('integrations.channel')
                    ->where('channel', 'telegram|telegram_ai|vk|max');

                // AI assistant settings.
                Route::get('/ai', AiAssistantPage::class)
                    ->name('ai');

                Route::get('/ai/{provider}', AiProviderAccessPage::class)
                    ->name('ai.provider')
                    ->where('provider', 'openai|deepseek|gigachat');

                // API and webhooks — External Sources token and webhook management.
                Route::get('/api-webhooks', ApiWebhooksPage::class)
                    ->name('api-webhooks');

                Route::get('/api-webhooks/{source}', ApiWebhookSourcePage::class)
                    ->name('api-webhooks.source')
                    ->where('source', '[0-9]+');

                // Team — manage operators, add new members, delete existing ones.
                Route::get('/team', TeamPage::class)
                    ->name('team');

                Route::get('/team/create', TeamMemberCreatePage::class)
                    ->name('team.create');

                Route::get('/team/{user}/edit', TeamMemberEditPage::class)
                    ->name('team.edit')
                    ->where('user', '[0-9]+');

                // Auto-replies — automatic responses to frequent questions (UI stage).
                Route::get('/auto-replies', AutoRepliesPage::class)
                    ->name('auto-replies');

                Route::get('/auto-replies/create', AutoReplyFormPage::class)
                    ->name('auto-replies.create');

                Route::get('/auto-replies/{rule}/edit', AutoReplyFormPage::class)
                    ->name('auto-replies.edit')
                    ->where('rule', '[0-9]+');
            });
    }
}
