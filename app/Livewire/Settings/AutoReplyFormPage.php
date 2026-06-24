<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AutoReply;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * «Новый автоответ» / «Редактирование автоответа» form screen.
 *
 * Create/edit form for an {@see AutoReply} record per the Pencil design `Lxnn0`
 * — trigger field, response textarea, active toggle, Cancel/Save. On save the
 * record is created or updated and the user is redirected back to the list.
 *
 * Routes:
 *   GET /admin/settings/auto-replies/create        → name auto-replies.create
 *   GET /admin/settings/auto-replies/{rule}/edit    → name auto-replies.edit
 * Layout: layouts.admin-settings (dark sidebar 280px + content area).
 */
#[Layout('layouts.admin-settings')]
class AutoReplyFormPage extends Component
{
    /**
     * Id of the edited rule (null when creating).
     */
    public ?int $ruleId = null;

    /**
     * Trigger word / phrase that activates the auto-reply.
     */
    public string $trigger = '';

    /**
     * Auto-reply response text.
     */
    public string $response = '';

    /**
     * Whether the rule is active.
     */
    public bool $enabled = true;

    /**
     * Boot the form. When an existing rule id is given, prefill the fields from
     * the database and switch the screen into edit mode.
     *
     * @param int|null $rule AutoReply id, or null for the create screen.
     */
    public function mount(?int $rule = null): void
    {
        if ($rule === null) {
            return;
        }

        $existing = AutoReply::find($rule);

        if ($existing === null) {
            return;
        }

        $this->ruleId = $existing->id;
        $this->trigger = $existing->trigger;
        $this->response = $existing->response;
        $this->enabled = $existing->enabled;
    }

    /**
     * Whether the screen is editing an existing rule (vs. creating a new one).
     */
    public function isEdit(): bool
    {
        return $this->ruleId !== null;
    }

    /**
     * Validate and persist the rule (create or update), then return to the list.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'trigger' => ['required', 'string', 'max:255'],
            'response' => ['required', 'string'],
            'enabled' => ['boolean'],
        ], [
            'trigger.required' => 'Введите триггер.',
            'trigger.max' => 'Триггер не должен превышать 255 символов.',
            'response.required' => 'Введите текст ответа.',
        ]);

        if ($this->ruleId !== null) {
            AutoReply::whereKey($this->ruleId)->update($validated);
        } else {
            AutoReply::create($validated);
        }

        $this->redirectRoute('admin.settings.auto-replies');
    }

    /**
     * Render the component view.
     *
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.auto-reply-form-page', [
            'isEdit' => $this->isEdit(),
        ]);
    }
}
