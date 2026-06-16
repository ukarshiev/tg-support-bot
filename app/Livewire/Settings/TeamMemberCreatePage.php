<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * «Новый участник» — add a team member (operator) with an explicit password.
 *
 * Opened from the «Добавить» button on {@see TeamPage}. Validates and creates a
 * User (name, email, password, role), then redirects back to the team list.
 *
 * Route:  GET /admin/settings/team/create
 * Name:   admin.settings.team.create
 * Access: authenticated admin only — managers are redirected to general settings.
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 */
#[Layout('layouts.admin-settings')]
class TeamMemberCreatePage extends Component
{
    use WithFileUploads;

    /** @var string Member name */
    public string $name = '';

    /** @var string Member email (login) */
    public string $email = '';

    /** @var string Plain password (hashed on create via the User cast) */
    public string $password = '';

    /** @var string Password confirmation */
    public string $password_confirmation = '';

    /** @var string Role slug: admin|manager */
    public string $role = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null Avatar image to upload (optional) */
    public $avatar = null;

    /**
     * Boot: redirect non-admins to general settings.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->isAdmin()) {
            $this->redirectRoute('admin.settings.general');
        }
    }

    /**
     * Validate and create the team member, then return to the team list.
     *
     * If an avatar was uploaded, it is stored on the `local` disk under
     * `avatars/user-{id}.jpg` after the user record is created.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ], [
            'name.required' => 'Введите имя.',
            'email.required' => 'Введите email.',
            'email.email' => 'Некорректный формат email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'password.required' => 'Введите пароль.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'role.required' => 'Выберите роль.',
            'role.in' => 'Недопустимая роль.',
            'avatar.image' => 'Файл должен быть изображением.',
            'avatar.max' => 'Размер изображения не должен превышать 2 МБ.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => UserRole::from($validated['role']),
        ]);

        if ($this->avatar !== null) {
            $path = $this->avatar->storeAs('avatars', "user-{$user->id}.jpg", 'local');
            $user->update(['avatar_path' => $path]);
        }

        $this->redirectRoute('admin.settings.team');
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.team-member-create-page');
    }
}
