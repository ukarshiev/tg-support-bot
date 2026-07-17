<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\TranslateAutoReplyJob;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\AutoReplyVariable;
use App\Models\TranslationJob;
use App\Modules\Translation\Services\SupportLanguageSettings;
use App\Services\AutoReplies\AutoReplyVariableRenderer;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin-settings')]
class AutoReplyFormPage extends Component
{
    public ?int $ruleId = null;

    public string $trigger = '';

    public string $response = '';

    public string $type = AutoReply::TYPE_REGULAR;

    public string $selectedLocale = 'en';

    public string $selectedTranslationText = '';

    public bool $overwriteManualTranslations = false;

    public bool $showTranslationPreview = false;

    public string $translationPreviewText = '';

    /** @var list<string> */
    public array $translationPreviewWarnings = [];

    public bool $enabled = true;

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
        $this->type = $existing->type;
        $this->trigger = $existing->trigger;
        $this->response = $existing->response;
        $this->enabled = $existing->enabled;
        $this->loadSelectedTranslation();
    }

    public function isEdit(): bool
    {
        return $this->ruleId !== null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'trigger' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:64'],
            'response' => ['required', 'string'],
            'enabled' => ['boolean'],
        ], [
            'trigger.required' => 'Введите триггер.',
            'trigger.max' => 'Триггер не должен превышать 255 символов.',
            'response.required' => 'Введите текст ответа.',
        ]);

        $validated['source_locale'] = 'ru';
        $validated['source_hash'] = AutoReply::sourceHash((string) $validated['response']);

        if (AutoReply::isSystemType((string) $validated['type'])) {
            $systemTrigger = AutoReply::systemTriggers()[$validated['type']];
            $duplicateExists = AutoReply::query()
                ->where('type', $validated['type'])
                ->where('trigger', $systemTrigger)
                ->when($this->ruleId !== null, fn ($query) => $query->whereKeyNot($this->ruleId))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'type' => 'Системный автоответ этого типа уже существует.',
                ]);
            }

            $validated['trigger'] = $systemTrigger;
        }

        if ($this->ruleId !== null) {
            AutoReply::whereKey($this->ruleId)->update($validated);
            $this->markStaleTranslations();
        } else {
            $created = AutoReply::create($validated);
            $this->ruleId = $created->id;
        }

        $this->redirectRoute('admin.settings.auto-replies');
    }

    public function updatedSelectedLocale(): void
    {
        $this->loadSelectedTranslation();
    }

    public function saveSelectedTranslation(): void
    {
        if ($this->ruleId === null || $this->selectedLocale === 'ru') {
            return;
        }

        AutoReplyTranslation::updateOrCreate(
            [
                'auto_reply_id' => $this->ruleId,
                'locale' => $this->selectedLocale,
            ],
            [
                'text' => $this->selectedTranslationText,
                'status' => AutoReplyTranslation::STATUS_READY,
                'source' => AutoReplyTranslation::SOURCE_MANUAL,
                'provider' => 'manual',
                'source_hash' => AutoReply::sourceHash($this->response),
                'translated_at' => now(),
            ]
        );

        $this->dispatch('admin-toast', message: 'Перевод сохранён.', type: 'success');
    }

    public function translateSelectedLanguage(SupportLanguageSettings $languages): void
    {
        if ($this->ruleId === null) {
            $this->dispatch('admin-toast', message: 'Сначала сохраните автоответ.', type: 'danger');
            return;
        }

        if ($this->selectedLocale === 'ru' || ! array_key_exists($this->selectedLocale, $languages->enabledLanguages())) {
            $this->dispatch('admin-toast', message: 'Выберите включённый язык перевода.', type: 'danger');
            return;
        }

        $translationJob = TranslationJob::create([
            'job_type' => TranslationJob::TYPE_AUTO_REPLY,
            'subject_type' => AutoReply::class,
            'subject_id' => $this->ruleId,
            'subject_label' => (AutoReply::typeLabels()[$this->type] ?? 'Автоответ') . ': ' . $this->trigger,
            'source_locale' => 'ru',
            'target_locale' => $this->selectedLocale,
            'status' => TranslationJob::STATUS_QUEUED,
            'characters' => mb_strlen($this->response),
            'queued_at' => now(),
            'meta' => [
                'overwrite_manual' => $this->overwriteManualTranslations,
                'single_language' => true,
                'source_preview' => mb_substr($this->response, 0, 220),
            ],
        ]);

        TranslateAutoReplyJob::dispatch($this->ruleId, $this->selectedLocale, $this->overwriteManualTranslations, $translationJob->id);

        $this->dispatch('admin-toast', message: 'Перевод текущего языка поставлен в очередь.', type: 'success');
    }

    public function previewSelectedTranslation(AutoReplyVariableRenderer $renderer): void
    {
        $source = $this->selectedLocale === 'ru' ? $this->response : $this->selectedTranslationText;
        [$text, $warnings] = $renderer->render($source);

        $this->translationPreviewText = $text;
        $this->translationPreviewWarnings = $warnings;
        $this->showTranslationPreview = true;
    }

    public function closeTranslationPreview(): void
    {
        $this->showTranslationPreview = false;
    }

    public function translateAllLanguages(SupportLanguageSettings $languages): void
    {
        if ($this->ruleId === null) {
            $this->save();
            return;
        }

        $queued = 0;

        foreach ($languages->enabledLanguages() as $code => $language) {
            if ($code === 'ru') {
                continue;
            }

            $translationJob = TranslationJob::create([
                'job_type' => TranslationJob::TYPE_AUTO_REPLY,
                'subject_type' => AutoReply::class,
                'subject_id' => $this->ruleId,
                'subject_label' => (AutoReply::typeLabels()[$this->type] ?? 'Автоответ') . ': ' . $this->trigger,
                'source_locale' => 'ru',
                'target_locale' => (string) $code,
                'status' => TranslationJob::STATUS_QUEUED,
                'characters' => mb_strlen($this->response),
                'queued_at' => now(),
                'meta' => [
                    'overwrite_manual' => $this->overwriteManualTranslations,
                    'language' => $language,
                    'source_preview' => mb_substr($this->response, 0, 220),
                ],
            ]);

            TranslateAutoReplyJob::dispatch($this->ruleId, (string) $code, $this->overwriteManualTranslations, $translationJob->id);
            $queued++;
        }

        if ($queued === 0) {
            $this->dispatch('admin-toast', message: 'Нет включённых языков для перевода.', type: 'danger');
            return;
        }

        $this->dispatch('admin-toast', message: "Перевод поставлен в очередь: {$queued} языков.", type: 'success');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.auto-reply-form-page', [
            'isEdit' => $this->isEdit(),
            'typeLabels' => AutoReply::typeLabels(),
            'languages' => app(SupportLanguageSettings::class)->enabledLanguages(),
            'translationStatuses' => $this->translationStatuses(),
            'variables' => AutoReplyVariable::query()->where('enabled', true)->orderBy('key')->get(),
            'clientVariables' => ['id', 'email', 'first_name', 'last_name', 'username', 'platform'],
        ]);
    }

    private function loadSelectedTranslation(): void
    {
        if ($this->ruleId === null || $this->selectedLocale === 'ru') {
            $this->selectedTranslationText = '';
            return;
        }

        $this->selectedTranslationText = (string) (AutoReplyTranslation::where('auto_reply_id', $this->ruleId)
            ->where('locale', $this->selectedLocale)
            ->value('text') ?? '');
    }

    private function markStaleTranslations(): void
    {
        if ($this->ruleId === null) {
            return;
        }

        $hash = AutoReply::sourceHash($this->response);
        AutoReplyTranslation::where('auto_reply_id', $this->ruleId)
            ->whereNotNull('source_hash')
            ->where('source_hash', '!=', $hash)
            ->update(['status' => AutoReplyTranslation::STATUS_STALE]);
    }

    /**
     * @return array<string, string>
     */
    private function translationStatuses(): array
    {
        if ($this->ruleId === null) {
            return [];
        }

        return AutoReplyTranslation::where('auto_reply_id', $this->ruleId)
            ->pluck('status', 'locale')
            ->all();
    }
}
