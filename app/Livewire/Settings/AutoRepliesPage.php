<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AutoReply;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Автоответы» settings page — list of auto-reply rules.
 *
 * Lists {@see AutoReply} records (trigger / response / actions) per the Pencil
 * design `l6ZD5a`. Delete removes the record from the database; the add/edit
 * controls navigate to {@see AutoReplyFormPage}.
 *
 * Route:  GET /admin/settings/auto-replies
 * Name:   admin.settings.auto-replies
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 */
#[Layout('layouts.admin-settings')]
class AutoRepliesPage extends Component
{
    /**
     * Delete an auto-reply rule by id.
     *
     * @param int $id
     */
    public function deleteRule(int $id): void
    {
        AutoReply::whereKey($id)->delete();
    }

    /**
     * Russian-pluralised label for the rules counter ("4 правила").
     *
     * @param int $count
     *
     * @return string
     */
    public function rulesCountLabel(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            $word = 'правило';
        } elseif ($mod10 >= 2 && $mod10 <= 4 && ! ($mod100 >= 12 && $mod100 <= 14)) {
            $word = 'правила';
        } else {
            $word = 'правил';
        }

        return "{$count} {$word}";
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        /** @var Collection<int, AutoReply> $rules */
        $rules = AutoReply::orderBy('id')->get();

        return view('livewire.settings.auto-replies-page', [
            'rules' => $rules,
        ]);
    }
}
