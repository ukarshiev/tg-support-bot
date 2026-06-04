<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

/**
 * Custom admin panel login page.
 *
 * Keeps Filament's authentication logic (validation, throttling, session
 * regeneration, redirect to the panel home) but renders a fully custom
 * two-panel view matching the Pencil design (brand panel + login form).
 *
 * The form state lives under the `data` state path (Filament default), so the
 * custom inputs bind via wire:model="data.email" / wire:model="data.password"
 * and submission calls authenticate().
 *
 * Registered via AdminPanelProvider::panel()->login(self::class).
 */
class Login extends BaseLogin
{
    /**
     * Custom Blade view for the login screen.
     *
     * @var view-string
     */
    protected static string $view = 'filament.pages.auth.login';
}
