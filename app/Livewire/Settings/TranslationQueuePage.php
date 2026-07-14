<?php

namespace App\Livewire\Settings;

use App\Models\AiSupportKnowledgeChunk;
use App\Models\AutoReply;
use App\Models\TranslationJob;
use App\Modules\Translation\Services\SupportLanguageSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin-settings')]
class TranslationQueuePage extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $typeFilter = '';

    public string $localeFilter = '';

    public string $search = '';

    public int $perPage = 25;

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedLocaleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->statusFilter = '';
        $this->typeFilter = '';
        $this->localeFilter = '';
        $this->search = '';
        $this->resetPage();
    }

    public function openSubjectUrl(TranslationJob $job): ?string
    {
        if ($job->subject_type === AutoReply::class && $job->subject_id !== null) {
            return route('admin.settings.auto-replies.edit', ['rule' => $job->subject_id]);
        }

        if ($job->subject_type === AiSupportKnowledgeChunk::class && $job->subject_id !== null) {
            return route('admin.settings.ai.knowledge', ['tab' => 'support']);
        }

        return null;
    }

    public function jobs(): LengthAwarePaginator
    {
        return TranslationJob::query()
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($query) => $query->where('job_type', $this->typeFilter))
            ->when($this->localeFilter !== '', fn ($query) => $query->where('target_locale', $this->localeFilter))
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($this->search)) . '%';
                $query->where(function ($nested) use ($term): void {
                    $nested->where('subject_label', 'like', $term)
                        ->orWhere('error_message', 'like', $term)
                        ->orWhere('provider', 'like', $term);
                });
            })
            ->latest('queued_at')
            ->latest('id')
            ->paginate($this->perPage);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.translation-queue-page', [
            'jobs' => $this->jobs(),
            'statusLabels' => TranslationJob::statusLabels(),
            'typeLabels' => TranslationJob::typeLabels(),
            'languages' => app(SupportLanguageSettings::class)->enabledLanguages(),
        ]);
    }
}
