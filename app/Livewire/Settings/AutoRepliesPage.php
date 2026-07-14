<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AutoReply;
use App\Models\AutoReplyVariable;
use App\Services\AutoReplies\AutoReplyVariableRenderer;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin-settings')]
class AutoRepliesPage extends Component
{
    public string $activeTab = 'auto-replies';

    public ?int $editingVariableId = null;

    public string $variableKey = '';

    public string $variableName = '';

    public string $variableValue = '';

    public string $variableDescription = '';

    public bool $variableEnabled = true;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['auto-replies', 'variables'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function deleteRule(int $id): void
    {
        $rule = AutoReply::find($id);
        if ($rule === null) {
            return;
        }

        if (AutoReply::isSystemType($rule->type)) {
            $this->dispatch('admin-toast', message: 'Системный автоответ нельзя удалить. Его можно отключить.', type: 'danger');
            return;
        }

        $rule->delete();
    }

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

    public function saveVariable(): void
    {
        $this->variableKey = AutoReplyVariable::normalizeKey($this->variableKey);

        $validated = $this->validate([
            'variableKey' => ['required', 'regex:/^[a-z0-9_]+$/', 'max:80', 'unique:auto_reply_variables,key,' . ($this->editingVariableId ?? 'NULL')],
            'variableName' => ['required', 'string', 'max:255'],
            'variableValue' => ['required', 'string', 'max:2000'],
            'variableDescription' => ['nullable', 'string', 'max:2000'],
            'variableEnabled' => ['boolean'],
        ], [
            'variableKey.required' => 'Введите ключ переменной.',
            'variableKey.regex' => 'Ключ: латиница, цифры и подчёркивание.',
            'variableKey.unique' => 'Такая переменная уже есть.',
            'variableName.required' => 'Введите название переменной.',
            'variableValue.required' => 'Введите значение переменной.',
        ]);

        AutoReplyVariable::updateOrCreate(
            ['id' => $this->editingVariableId],
            [
                'key' => $validated['variableKey'],
                'name' => $validated['variableName'],
                'value' => $validated['variableValue'],
                'description' => $validated['variableDescription'] ?? null,
                'enabled' => $validated['variableEnabled'],
            ]
        );

        $this->resetVariableForm();
        $this->dispatch('admin-toast', message: 'Переменная сохранена.', type: 'success');
    }

    public function editVariable(int $id): void
    {
        $variable = AutoReplyVariable::find($id);
        if ($variable === null) {
            return;
        }

        $this->editingVariableId = $variable->id;
        $this->variableKey = $variable->key;
        $this->variableName = $variable->name;
        $this->variableValue = $variable->value;
        $this->variableDescription = (string) $variable->description;
        $this->variableEnabled = (bool) $variable->enabled;
        $this->activeTab = 'variables';
    }

    public function deleteVariable(int $id, AutoReplyVariableRenderer $renderer): void
    {
        $variable = AutoReplyVariable::find($id);
        if ($variable === null) {
            return;
        }

        $token = AutoReplyVariable::token($variable->key);
        $isUsed = AutoReply::query()->where('response', 'like', '%' . $token . '%')->exists()
            || \App\Models\AutoReplyTranslation::query()->where('text', 'like', '%' . $token . '%')->exists();

        if ($isUsed) {
            $variable->update(['enabled' => false]);
            $this->dispatch('admin-toast', message: 'Переменная используется, поэтому она выключена, а не удалена.', type: 'danger');
            return;
        }

        $variable->delete();
        $this->dispatch('admin-toast', message: 'Переменная удалена.', type: 'success');
    }

    public function resetVariableForm(): void
    {
        $this->editingVariableId = null;
        $this->variableKey = '';
        $this->variableName = '';
        $this->variableValue = '';
        $this->variableDescription = '';
        $this->variableEnabled = true;
        $this->resetValidation();
    }

    public function render(): \Illuminate\View\View
    {
        /** @var Collection<int, AutoReply> $rules */
        $rules = AutoReply::query()
            ->orderByRaw(
                'case type when ? then 0 when ? then 1 when ? then 2 when ? then 3 else 4 end',
                [
                    AutoReply::TYPE_WELCOME,
                    AutoReply::TYPE_REGULAR,
                    AutoReply::TYPE_DIALOG_CLOSED,
                    AutoReply::TYPE_BAN,
                ]
            )
            ->orderBy('id')
            ->get();

        return view('livewire.settings.auto-replies-page', [
            'rules' => $rules,
            'variables' => AutoReplyVariable::query()->orderBy('key')->get(),
        ]);
    }
}
