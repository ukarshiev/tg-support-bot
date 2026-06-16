<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Команда» settings page — list of team members.
 *
 * The "Добавить" button navigates to {@see TeamMemberCreatePage} (a dedicated
 * add-user screen). The members table lists users with a delete action
 * (single trash button + native confirm; self-delete protection).
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
    /**
     * Error shown when a delete action is rejected.
     */
    public ?string $deleteError = null;

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

    // ── Delete ─────────────────────────────────────────────────────────────────

    /**
     * Delete a team member.
     *
     * Guard: an admin cannot delete their own account (self-lockout protection);
     * the trash button is also hidden for the current user in the view.
     *
     * @param int $userId
     */
    public function deleteMember(int $userId): void
    {
        $this->deleteError = null;

        /** @var User|null $current */
        $current = Auth::user();

        if ($current && $current->id === $userId) {
            $this->deleteError = 'Вы не можете удалить собственный аккаунт.';

            return;
        }

        $member = User::find($userId);

        if (! $member) {
            return;
        }

        if ($member->avatar_path !== null) {
            Storage::disk('local')->delete($member->avatar_path);
        }

        $member->delete();
    }

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
