<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Standalone admin login screen (pure Livewire, no Filament).
 *
 * Renders the two-panel "Авторизация" design and authenticates the operator
 * against the default `web` guard. Replaces the former Filament login page.
 *
 * Registered as a full-page route at GET /admin/login (name: login) in
 * App\Modules\Admin\AdminServiceProvider.
 */
#[Layout('layouts.admin-auth')]
class LoginPage extends Component
{
    /** Email entered in the form. */
    public string $email = '';

    /** Password entered in the form. */
    public string $password = '';

    /** Maximum failed attempts before throttling kicks in. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Validate credentials, throttle brute-force attempts and sign the user in.
     *
     * On success regenerates the session and redirects to the intended URL
     * (falling back to the chat workspace).
     *
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws ValidationException When credentials are invalid or throttled.
     */
    public function authenticate()
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], attributes: [
            'email' => 'email',
            'password' => 'пароль',
        ]);

        $key = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Слишком много попыток входа. Повторите через {$seconds} с.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], true)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return redirect()->intended(route('admin.chats'));
    }

    /**
     * Per-identifier + IP rate-limit key for login throttling.
     *
     * @return string
     */
    private function throttleKey(): string
    {
        return 'login:' . Str::lower($this->email) . '|' . request()->ip();
    }

    /**
     * Render the login view.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.auth.login-page');
    }
}
