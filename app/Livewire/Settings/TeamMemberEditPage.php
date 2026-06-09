<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Редактирование участника» — edit a team member (name, email, role) and
 * optionally reset the password.
 *
 * Opened by clicking a row on {@see TeamPage}. Validates and updates the User,
 * then redirects back to the team list.
 *
 * Route:  GET /admin/settings/team/{user}/edit
 * Name:   admin.settings.team.edit
 * Access: authenticated admin only — managers are redirected to general settings.
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 */
#[Layout('layouts.admin-settings')]
class TeamMemberEditPage extends Component
{
    /** @var int The edited user id */
    public int $userId = 0;

    /** @var string Member name */
    public string $name = '';

    /** @var string Member email (login) */
    public string $email = '';

    /** @var string Role slug: admin|manager */
    public string $role = '';

    /** @var string New password (optional — blank keeps the current one) */
    public string $password = '';

    /** @var string New password confirmation */
    public string $password_confirmation = '';

    /**
     * Boot: guard admin access, then prefill from the user.
     *
     * @param int $user
     */
    public function mount(int $user): void
    {
        /** @var User|null $current */
        $current = Auth::user();

        if (! $current || ! $current->isAdmin()) {
            $this->redirectRoute('admin.settings.general');

            return;
        }

        $member = User::find($user);

        if (! $member) {
            $this->redirectRoute('admin.settings.team');

            return;
        }

        $this->userId = $member->id;
        $this->name = $member->name;
        $this->email = $member->email;
        $this->role = $member->role->value;
    }

    /**
     * Validate and persist the changes, then return to the team list.
     *
     * Password is optional: a blank value keeps the current password.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Введите имя.',
            'email.required' => 'Введите email.',
            'email.email' => 'Некорректный формат email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'role.required' => 'Выберите роль.',
            'role.in' => 'Недопустимая роль.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
        ]);

        $member = User::find($this->userId);

        if (! $member) {
            $this->redirectRoute('admin.settings.team');

            return;
        }

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        $member->update($data);

        $this->redirectRoute('admin.settings.team');
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.team-member-edit-page');
    }
}
