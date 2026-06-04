<?php

namespace App\Modules\Admin;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * @param Panel $panel
     *
     * @return Panel
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(\App\Modules\Admin\Filament\Pages\Auth\Login::class)
            ->homeUrl(fn (): string => route('admin.chats'))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(
                in: app_path('Modules/Admin/Filament/Resources'),
                for: 'App\\Modules\\Admin\\Filament\\Resources'
            )
            ->discoverPages(
                in: app_path('Modules/Admin/Filament/Pages'),
                for: 'App\\Modules\\Admin\\Filament\\Pages'
            )
            ->navigationItems([
                NavigationItem::make('Диалоги')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (): string => route('admin.chats'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('admin.chats'))
                    ->sort(1),
                NavigationItem::make('Настройки')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (): string => route('admin.settings.general'))
                    ->isActiveWhen(fn (): bool => request()->routeIs('admin.settings.*'))
                    ->sort(2),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
