<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

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

    /** @var string|null Current avatar path stored in the DB */
    public ?string $currentAvatarPath = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null New avatar image to upload (optional) */
    public $avatar = null;

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
        $this->currentAvatarPath = $member->avatar_path;
    }

    /**
     * Validate and persist the changes, then return to the team list.
     *
     * Password is optional: a blank value keeps the current password.
     * If a new avatar was uploaded, it overwrites the existing one.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ], [
            'name.required' => 'Введите имя.',
            'email.required' => 'Введите email.',
            'email.email' => 'Некорректный формат email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'role.required' => 'Выберите роль.',
            'role.in' => 'Недопустимая роль.',
            'password.min' => 'Пароль должен быть не короче 8 символов.',
            'password.confirmed' => 'Пароли не совпадают.',
            'avatar.image' => 'Файл должен быть изображением.',
            'avatar.max' => 'Размер изображения не должен превышать 2 МБ.',
        ]);

        $member = User::find($this->userId);

        if (! $member) {
            $this->redirectRoute('admin.settings.team');

            return;
        }

        if ($member->isAdmin()
            && $validated['role'] !== UserRole::Admin->value
            && User::query()->where('role', UserRole::Admin->value)->count() === 1) {
            $this->addError('role', 'Нельзя понизить единственного администратора.');

            return;
        }

        $securityContextChanged = $member->role->value !== $validated['role']
            || ! empty($validated['password']);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
        ];

        if (! empty($validated['password'])) {
            $data['password'] = $validated['password'];
        }

        if ($this->avatar !== null) {
            $path = $this->avatar->storeAs('avatars', "user-{$member->id}.jpg", 'local');
            $data['avatar_path'] = $path;
        }

        $member->update($data);

        if ($securityContextChanged && Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $member->id)
                ->delete();
        }

        $this->redirectRoute('admin.settings.team');
    }

    /**
     * Remove the current avatar: delete the file from local disk and null avatar_path.
     */
    public function removeAvatar(): void
    {
        $member = User::find($this->userId);

        if (! $member) {
            return;
        }

        if ($member->avatar_path !== null) {
            Storage::disk('local')->delete($member->avatar_path);
            $member->update(['avatar_path' => null]);
            $this->currentAvatarPath = null;
        }

        $this->avatar = null;
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
