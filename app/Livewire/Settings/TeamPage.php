<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\UserRole;
use App\Models\User;
use App\Modules\Admin\Actions\InviteOperator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Команда» settings page — manage operators and their roles.
 *
 * Two sections:
 *  1. "Пригласить оператора" card — email + role form → InviteOperator action.
 *  2. "Участники команды" table — list with delete action (self-delete protection).
 *
 * Route:  GET /admin/settings/team
 * Name:   admin.settings.team
 * Access: authenticated admin only — managers are redirected to general settings.
 *         Guest users are blocked by the route-level Filament Authenticate middleware.
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 *
 * Online status: v1 stub — no real last_seen_at tracking yet.
 * The status column is rendered as a placeholder badge (see Blade view).
 */
#[Layout('layouts.admin-settings')]
class TeamPage extends Component
{
    // ── Invite form ────────────────────────────────────────────────────────────

    /**
     * Email address for the invited operator.
     */
    public string $inviteEmail = '';

    /**
     * Role for the invited operator ('admin' or 'manager').
     */
    public string $inviteRole = '';

    /**
     * Success notice shown after a successful invite.
     */
    public ?string $inviteSuccess = null;

    /**
     * Error notice shown when the invite action fails.
     */
    public ?string $inviteError = null;

    /**
     * Generated password revealed once when the invitation email could not be sent,
     * so the admin can hand it to the operator manually (null when mail was sent).
     */
    public ?string $invitedPassword = null;

    // ── Delete confirmation ────────────────────────────────────────────────────

    /**
     * ID of the member whose delete confirmation is pending (null = none).
     */
    public ?int $confirmDeleteId = null;

    /**
     * Error shown when a delete action is rejected.
     */
    public ?string $deleteError = null;

    // ── Mount ──────────────────────────────────────────────────────────────────

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

    // ── Computed helpers ───────────────────────────────────────────────────────

    /**
     * Load all users ordered by role (admin first) then name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getMembers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
    }

    /**
     * Deterministic avatar background colour for a user.
     * Derived from the user's email — produces one of 8 palette colours.
     *
     * @param User $user
     *
     * @return string Hex colour string.
     */
    public function avatarColor(User $user): string
    {
        $palette = [
            '#5B6ABF', '#E85D75', '#34C759', '#F5A623',
            '#06B6D4', '#10B981', '#8B5CF6', '#EF4444',
        ];

        return $palette[abs(crc32($user->email)) % 8];
    }

    /**
     * Two-letter uppercase initials from the user's name or email.
     *
     * @param User $user
     *
     * @return string
     */
    public function avatarInitials(User $user): string
    {
        $name = trim($user->name);

        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);

            if (count($parts) >= 2) {
                return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
            }

            return strtoupper(mb_substr($name, 0, 2));
        }

        // Fall back to first 2 chars of email local-part
        $parts = explode('@', $user->email);
        $local = $parts[0];

        return strtoupper(mb_substr($local, 0, 2));
    }

    // ── Invite ─────────────────────────────────────────────────────────────────

    /**
     * Validate the invite form and call InviteOperator.
     */
    public function invite(): void
    {
        $this->inviteSuccess = null;
        $this->inviteError = null;
        $this->invitedPassword = null;

        $this->validate([
            'inviteEmail' => ['required', 'email', Rule::unique('users', 'email')],
            'inviteRole' => ['required', Rule::in(array_keys(UserRole::options()))],
        ], [
            'inviteEmail.required' => 'Введите email.',
            'inviteEmail.email' => 'Некорректный формат email.',
            'inviteEmail.unique' => 'Пользователь с таким email уже существует.',
            'inviteRole.required' => 'Выберите роль.',
            'inviteRole.in' => 'Недопустимая роль.',
        ]);

        try {
            $result = InviteOperator::execute(
                $this->inviteEmail,
                UserRole::from($this->inviteRole),
            );
        } catch (\Throwable) {
            $this->inviteError = 'Не удалось создать оператора. Попробуйте ещё раз.';

            return;
        }

        $email = $this->inviteEmail;
        $this->inviteEmail = '';
        $this->inviteRole = '';
        $this->resetValidation();

        if ($result['mail_sent']) {
            $this->inviteSuccess = "Приглашение отправлено на {$email}.";

            return;
        }

        // Mail could not be delivered — operator is created; reveal the password once.
        $this->inviteSuccess = "Оператор {$email} создан, но письмо отправить не удалось (проверьте настройки SMTP). Передайте пароль вручную:";
        $this->invitedPassword = $result['password'];
    }

    /**
     * Hide the one-time revealed password.
     */
    public function dismissInvitedPassword(): void
    {
        $this->invitedPassword = null;
    }

    // ── Delete ─────────────────────────────────────────────────────────────────

    /**
     * Begin a delete confirmation for the given member.
     *
     * @param int $userId
     */
    public function confirmDelete(int $userId): void
    {
        $this->deleteError = null;
        $this->confirmDeleteId = $userId;
    }

    /**
     * Cancel the pending delete confirmation.
     */
    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
        $this->deleteError = null;
    }

    /**
     * Execute the confirmed delete.
     *
     * Guards:
     *  - Admin cannot delete themselves (self-lockout protection).
     *  - User must exist.
     */
    public function deleteMember(): void
    {
        $this->deleteError = null;

        /** @var User|null $current */
        $current = Auth::user();

        if ($this->confirmDeleteId === null) {
            return;
        }

        if ($current && $current->id === $this->confirmDeleteId) {
            $this->deleteError = 'Вы не можете удалить собственный аккаунт.';
            $this->confirmDeleteId = null;

            return;
        }

        $member = User::find($this->confirmDeleteId);

        if (! $member) {
            $this->deleteError = 'Участник не найден.';
            $this->confirmDeleteId = null;

            return;
        }

        $member->delete();
        $this->confirmDeleteId = null;
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.team-page', [
            'members' => $this->getMembers(),
        ]);
    }
}
