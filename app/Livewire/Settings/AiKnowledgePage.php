<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Jobs\TranslateSupportCaseJob;
use App\Models\AiKnowledgeItem;
use App\Models\AiSupportKnowledgeChunk;
use App\Models\AiSupportMessage;
use App\Models\TranslationJob;
use App\Modules\Ai\Support\SupportArchiveImportService;
use App\Modules\Ai\Support\SupportCaseModeratorService;
use App\Modules\Ai\Support\SupportCurrentDialogImportService;
use App\Services\Settings\SettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * AI knowledge base CRUD screen.
 *
 * Route: GET /admin/settings/ai/knowledge
 */
#[Layout('layouts.admin-settings')]
class AiKnowledgePage extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'sort', except: 'priority')]
    public string $sortField = 'priority';

    #[Url(as: 'dir', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'tab', except: 'support')]
    public string $activeTab = 'support';

    #[Url(as: 'support_q', except: '')]
    public string $supportSearch = '';

    #[Url(as: 'support_status', except: 'all')]
    public string $supportStatusFilter = 'all';

    #[Url(as: 'support_sort', except: 'first_message_at')]
    public string $supportSortField = 'first_message_at';

    #[Url(as: 'support_dir', except: 'desc')]
    public string $supportSortDirection = 'desc';

    public bool $drawerOpen = false;

    public string $drawerMode = 'view';

    public ?int $editingId = null;

    public bool $supportDrawerOpen = false;

    public ?int $selectedSupportChunkId = null;

    /** @var array{question_ru: string, answer_ru: string, ai_instruction: string} */
    public array $supportForm = [
        'question_ru' => '',
        'answer_ru' => '',
        'ai_instruction' => '',
    ];

    public bool $fillAiModalOpen = false;

    public int $fillAiLimit = 100;

    /** @var array{dialogs_count: int, messages_count: int, chunks_count: int, dry_run: bool}|null */
    public ?array $fillAiPreview = null;

    public bool $archiveImportModalOpen = false;

    public string $archiveImportPath = '';

    /** @var array{messages_count: int, chunks_count: int, created_chunks_count: int, created_source_hashes: array<int, string>, dry_run: bool, files: array<int, string>}|null */
    public ?array $archiveImportPreview = null;

    /** @var array{title: string, slug: string, content: string, keywords: string, priority: int|string, is_active: bool} */
    public array $form = [
        'title' => '',
        'slug' => '',
        'content' => '',
        'keywords' => '',
        'priority' => 100,
        'is_active' => true,
    ];

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->normalizeActiveTab();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->normalizeActiveTab();
        $this->resetPage();
    }

    public function updatedActiveTab(): void
    {
        $this->normalizeActiveTab();
        $this->resetPage();
    }

    public function updatedSupportSearch(): void
    {
        $this->resetPage('supportPage');
    }

    public function updatedSupportStatusFilter(): void
    {
        $this->resetPage('supportPage');
    }

    public function sortSupportBy(string $field): void
    {
        if (! in_array($field, ['first_message_at', 'last_message_at', 'created_at', 'status'], true)) {
            return;
        }

        if ($this->supportSortField === $field) {
            $this->supportSortDirection = $this->supportSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->supportSortField = $field;
            $this->supportSortDirection = 'desc';
        }

        $this->resetPage('supportPage');
    }

    public function openSupportChunk(int $id): void
    {
        $this->resetMessages();

        if (AiSupportKnowledgeChunk::whereKey($id)->doesntExist()) {
            $this->errorMessage = 'Support-кейс не найден.';

            return;
        }

        $this->selectedSupportChunkId = $id;
        $this->loadSupportForm($id);
        $this->supportDrawerOpen = true;
    }

    public function closeSupportDrawer(): void
    {
        $this->supportDrawerOpen = false;
        $this->selectedSupportChunkId = null;
        $this->supportForm = [
            'question_ru' => '',
            'answer_ru' => '',
            'ai_instruction' => '',
        ];
    }

    public function setSupportChunkStatus(int $id, string $status): void
    {
        $this->resetMessages();

        if (! in_array($status, AiSupportKnowledgeChunk::statuses(), true)) {
            $this->errorMessage = 'Неизвестный статус support-кейса.';

            return;
        }

        $chunk = AiSupportKnowledgeChunk::find($id);
        if ($chunk === null) {
            $this->errorMessage = 'Support-кейс не найден.';

            return;
        }

        $chunk->status = $status;
        $chunk->is_active = $status === AiSupportKnowledgeChunk::STATUS_ACTIVE;
        $chunk->save();

        $this->successMessage = 'Статус support-кейса: ' . $chunk->statusLabel() . '.';
    }

    public function deleteSupportChunk(int $id): void
    {
        $this->resetMessages();
        AiSupportKnowledgeChunk::whereKey($id)->delete();

        if ($this->selectedSupportChunkId === $id) {
            $this->closeSupportDrawer();
        }

        $this->successMessage = 'Support-кейс удалён.';
        $this->resetPage('supportPage');
    }

    public function saveSupportCanonicalFields(): void
    {
        $this->resetMessages();

        $chunk = $this->selectedSupportChunk();
        if ($chunk === null) {
            $this->errorMessage = 'Support-кейс не найден.';

            return;
        }

        $questionRu = trim((string) $this->supportForm['question_ru']);
        $answerRu = trim((string) $this->supportForm['answer_ru']);
        $instruction = trim((string) $this->supportForm['ai_instruction']);

        if ($questionRu !== (string) ($chunk->question_ru ?? '')) {
            $chunk->question_ru = $questionRu !== '' ? $questionRu : null;
            $chunk->question_ru_manually_edited = $questionRu !== '';
            $chunk->question_translation_status = $questionRu !== ''
                ? AiSupportKnowledgeChunk::TRANSLATION_MANUAL_EDITED
                : AiSupportKnowledgeChunk::TRANSLATION_PENDING;
            $chunk->question_translation_error = null;
            $chunk->question_translated_at = $questionRu !== '' ? now() : null;
        }

        if ($answerRu !== (string) ($chunk->answer_ru ?? '')) {
            $chunk->answer_ru = $answerRu !== '' ? $answerRu : null;
            $chunk->answer_ru_manually_edited = $answerRu !== '';
            $chunk->answer_translation_status = $answerRu !== ''
                ? AiSupportKnowledgeChunk::TRANSLATION_MANUAL_EDITED
                : AiSupportKnowledgeChunk::TRANSLATION_PENDING;
            $chunk->answer_translation_error = null;
            $chunk->answer_translated_at = $answerRu !== '' ? now() : null;
        }

        $chunk->ai_instruction = $instruction !== '' ? $instruction : null;
        $chunk->save();

        $this->successMessage = 'RU canonical и инструкция AI сохранены.';
        $this->loadSupportForm($chunk->id);
    }

    public function translateSupportChunk(string $field = 'all', bool $force = false): void
    {
        $this->resetMessages();

        if (! in_array($field, ['question', 'answer', 'all'], true)) {
            $this->errorMessage = 'Неизвестное поле для перевода.';

            return;
        }

        $chunk = $this->selectedSupportChunk();
        if ($chunk === null) {
            $this->errorMessage = 'Support-кейс не найден.';

            return;
        }

        $monitor = $this->queueSupportTranslation($chunk, $field, $force);
        $this->successMessage = 'Перевод support-кейса поставлен в очередь #' . $monitor->id . '.';
    }

    public function translateAllSupportChunks(): void
    {
        $this->resetMessages();

        $queued = 0;
        AiSupportKnowledgeChunk::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('question_ru')
                    ->orWhere('question_ru', '')
                    ->orWhereNull('answer_ru')
                    ->orWhere('answer_ru', '')
                    ->orWhereIn('question_translation_status', [AiSupportKnowledgeChunk::TRANSLATION_PENDING, AiSupportKnowledgeChunk::TRANSLATION_FAILED, AiSupportKnowledgeChunk::TRANSLATION_NEEDS_REVIEW])
                    ->orWhereIn('answer_translation_status', [AiSupportKnowledgeChunk::TRANSLATION_PENDING, AiSupportKnowledgeChunk::TRANSLATION_FAILED, AiSupportKnowledgeChunk::TRANSLATION_NEEDS_REVIEW]);
            })
            ->where('question_ru_manually_edited', false)
            ->where('answer_ru_manually_edited', false)
            ->orderBy('id')
            ->limit(1000)
            ->get()
            ->each(function (AiSupportKnowledgeChunk $chunk) use (&$queued): void {
                $this->queueSupportTranslation($chunk, 'all', false);
                $queued++;
            });

        $this->successMessage = $queued > 0
            ? 'Массовый RU canonical поставлен в очередь: ' . $queued . ' кейсов.'
            : 'Нет support-кейсов для постановки в очередь.';
    }

    public function rebuildSupportChunks(): void
    {
        $this->resetMessages();

        $service = app(SupportArchiveImportService::class);
        $result = $service->rebuildChunksFromStoredMessages();
        $this->successMessage = 'Support-фрагменты переиндексированы: ' . $result['chunks_count'] . '.';
    }

    public function openFillAiModal(): void
    {
        $this->resetMessages();
        $this->fillAiModalOpen = true;
        $this->refreshFillAiPreview();
    }

    public function closeFillAiModal(): void
    {
        $this->fillAiModalOpen = false;
        $this->fillAiPreview = null;
    }

    public function refreshFillAiPreview(): void
    {
        $this->fillAiLimit = max(1, min(1000, (int) $this->fillAiLimit));
        $this->fillAiPreview = app(SupportCurrentDialogImportService::class)->import(false, $this->fillAiLimit);
    }

    public function fillAiFromCurrentDialogs(): void
    {
        $this->resetMessages();
        $this->fillAiLimit = max(1, min(1000, (int) $this->fillAiLimit));

        $import = app(SupportCurrentDialogImportService::class)->import(true, $this->fillAiLimit);
        $moderation = app(SupportCaseModeratorService::class)->moderateBySourceHashes($import['created_source_hashes']);

        $this->successMessage = sprintf(
            'База AI пополнена: новых %d из %d, проверено AI %d, обновлено %d, ошибок %d.',
            $import['created_chunks_count'],
            $import['chunks_count'],
            $moderation['checked'],
            $moderation['updated'],
            $moderation['failed'],
        );
        $this->fillAiModalOpen = false;
        $this->fillAiPreview = null;
        $this->supportStatusFilter = 'review';
        $this->resetPage('supportPage');
    }

    public function openArchiveImportModal(): void
    {
        $this->resetMessages();
        $this->archiveImportModalOpen = true;
        $this->archiveImportPath = (string) (app(SettingsService::class)->get('ai.support_archive_path') ?? '');
        $this->refreshArchiveImportPreview();
    }

    public function closeArchiveImportModal(): void
    {
        $this->archiveImportModalOpen = false;
        $this->archiveImportPreview = null;
    }

    public function refreshArchiveImportPreview(): void
    {
        $this->resetMessages();
        $path = trim($this->archiveImportPath);
        if ($path === '') {
            $this->archiveImportPreview = null;
            $this->errorMessage = 'Укажите папку архива.';

            return;
        }

        if (! is_dir($path)) {
            $this->archiveImportPreview = null;
            $this->errorMessage = 'Папка архива не найдена.';

            return;
        }

        $this->archiveImportPreview = app(SupportArchiveImportService::class)->import($path, false);
    }

    public function fillAiFromArchive(): void
    {
        $this->resetMessages();
        $path = trim($this->archiveImportPath);
        if ($path === '' || ! is_dir($path)) {
            $this->errorMessage = 'Папка архива не найдена.';

            return;
        }

        app(SettingsService::class)->set('ai.support_archive_path', $path);

        $import = app(SupportArchiveImportService::class)->import($path, true);
        $moderation = app(SupportCaseModeratorService::class)->moderateBySourceHashes($import['created_source_hashes']);

        $this->successMessage = sprintf(
            'Архив добавлен в базу AI: новых %d из %d, проверено AI %d, обновлено %d, ошибок %d.',
            $import['created_chunks_count'],
            $import['chunks_count'],
            $moderation['checked'],
            $moderation['updated'],
            $moderation['failed'],
        );
        $this->archiveImportModalOpen = false;
        $this->archiveImportPreview = null;
        $this->supportStatusFilter = 'review';
        $this->resetPage('supportPage');
    }

    public function toggleSupportChunk(int $id): void
    {
        $this->resetMessages();

        $chunk = AiSupportKnowledgeChunk::find($id);
        if ($chunk === null) {
            $this->errorMessage = 'Support-фрагмент не найден.';

            return;
        }

        $newStatus = $chunk->status === AiSupportKnowledgeChunk::STATUS_ACTIVE
            ? AiSupportKnowledgeChunk::STATUS_DISABLED
            : AiSupportKnowledgeChunk::STATUS_ACTIVE;

        $this->setSupportChunkStatus($id, $newStatus);
    }

    public function updatedSearch(): void
    {
        $this->resetPage('blocksPage');
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage('blocksPage');
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['priority', 'title', 'updated_at'], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'updated_at' ? 'desc' : 'asc';
        }

        $this->resetPage('blocksPage');
    }

    public function openCreate(): void
    {
        $this->resetMessages();
        $this->resetValidation();
        $this->editingId = null;
        $this->drawerMode = 'create';
        $this->activeTab = 'blocks';
        $this->form = [
            'title' => '',
            'slug' => '',
            'content' => '',
            'keywords' => '',
            'priority' => 100,
            'is_active' => true,
        ];
        $this->drawerOpen = true;
    }

    public function openView(int $id): void
    {
        $this->loadItemIntoDrawer($id, 'view');
    }

    public function openEdit(int $id): void
    {
        $this->loadItemIntoDrawer($id, 'edit');
    }

    public function switchToEdit(): void
    {
        if ($this->editingId !== null) {
            $this->drawerMode = 'edit';
        }
    }

    public function closeDrawer(): void
    {
        $this->drawerOpen = false;
        $this->drawerMode = 'view';
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->resetMessages();
        $this->validate($this->rules(), $this->messages());

        $payload = [
            'title' => trim((string) $this->form['title']),
            'slug' => trim((string) $this->form['slug']),
            'content' => trim((string) $this->form['content']),
            'keywords' => $this->parseKeywords((string) $this->form['keywords']),
            'priority' => (int) $this->form['priority'],
            'is_active' => (bool) $this->form['is_active'],
        ];

        if ($this->editingId === null) {
            AiKnowledgeItem::create($payload);
            $this->successMessage = 'Блок знаний создан.';
        } else {
            AiKnowledgeItem::whereKey($this->editingId)->update($payload);
            $this->successMessage = 'Блок знаний обновлён.';
        }

        $this->closeDrawer();
        $this->resetPage('blocksPage');
    }

    public function toggleActive(int $id): void
    {
        $this->resetMessages();
        $item = AiKnowledgeItem::find($id);
        if ($item === null) {
            $this->errorMessage = 'Блок знаний не найден.';

            return;
        }

        $item->is_active = ! $item->is_active;
        $item->save();

        $this->successMessage = $item->is_active ? 'Блок включён.' : 'Блок выключен.';
    }

    public function deleteItem(int $id): void
    {
        $this->resetMessages();
        AiKnowledgeItem::whereKey($id)->delete();

        if ($this->editingId === $id) {
            $this->closeDrawer();
        }

        $this->successMessage = 'Блок знаний удалён.';
        $this->resetPage('blocksPage');
    }

    public function getTotalCountProperty(): int
    {
        return AiKnowledgeItem::count();
    }

    public function getActiveCountProperty(): int
    {
        return AiKnowledgeItem::where('is_active', true)->count();
    }

    public function getInactiveCountProperty(): int
    {
        return AiKnowledgeItem::where('is_active', false)->count();
    }

    /**
     * @return array<int, string>
     */
    public function parseKeywords(string $keywords): array
    {
        return collect(explode(',', $keywords))
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.ai-knowledge-page', [
            'items' => $this->items(),
            'supportChunks' => $this->supportChunks(),
            'moderationInfo' => $this->moderationInfo(),
            'selectedSupportChunk' => $this->selectedSupportChunk(),
        ]);
    }

    public function getSupportMessagesCountProperty(): int
    {
        return AiSupportMessage::count();
    }

    public function getSupportChunksCountProperty(): int
    {
        return AiSupportKnowledgeChunk::count();
    }

    public function getActiveSupportChunksCountProperty(): int
    {
        return AiSupportKnowledgeChunk::where('status', AiSupportKnowledgeChunk::STATUS_ACTIVE)->where('is_active', true)->count();
    }

    public function getReviewSupportChunksCountProperty(): int
    {
        return AiSupportKnowledgeChunk::where('status', AiSupportKnowledgeChunk::STATUS_REVIEW)->count();
    }

    public function getDisabledSupportChunksCountProperty(): int
    {
        return AiSupportKnowledgeChunk::where('status', AiSupportKnowledgeChunk::STATUS_DISABLED)->count();
    }

    /**
     * @return array<string, int>
     */
    public function getSupportTranslationStatsProperty(): array
    {
        $stats = array_fill_keys(AiSupportKnowledgeChunk::translationStatuses(), 0);

        foreach (['question_translation_status', 'answer_translation_status'] as $column) {
            AiSupportKnowledgeChunk::query()
                ->selectRaw($column . ' as status, count(*) as total')
                ->groupBy($column)
                ->pluck('total', 'status')
                ->each(function (int $total, string $status) use (&$stats): void {
                    if ($status !== '') {
                        $stats[$status] = ($stats[$status] ?? 0) + $total;
                    }
                });
        }

        return $stats;
    }

    /**
     * @return LengthAwarePaginator<int, AiKnowledgeItem>
     */
    private function items(): LengthAwarePaginator
    {
        $sortField = in_array($this->sortField, ['priority', 'title', 'updated_at'], true)
            ? $this->sortField
            : 'priority';
        $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        return AiKnowledgeItem::query()
            ->when($this->statusFilter === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when(trim($this->search) !== '', fn (Builder $query) => $this->applySearch($query, trim($this->search)))
            ->orderBy($sortField, $sortDirection)
            ->orderBy('id')
            ->paginate(10, pageName: 'blocksPage');
    }

    /**
     * @return LengthAwarePaginator<int, AiSupportKnowledgeChunk>
     */
    private function supportChunks(): LengthAwarePaginator
    {
        $sortField = in_array($this->supportSortField, ['first_message_at', 'last_message_at', 'created_at', 'status'], true)
            ? $this->supportSortField
            : 'first_message_at';
        $sortDirection = $this->supportSortDirection === 'asc' ? 'asc' : 'desc';
        $search = trim($this->supportSearch);

        return AiSupportKnowledgeChunk::query()
            ->when($this->supportStatusFilter === 'active', fn (Builder $query) => $query->where('status', AiSupportKnowledgeChunk::STATUS_ACTIVE))
            ->when($this->supportStatusFilter === 'review', fn (Builder $query) => $query->where('status', AiSupportKnowledgeChunk::STATUS_REVIEW))
            ->when($this->supportStatusFilter === 'disabled', fn (Builder $query) => $query->where('status', AiSupportKnowledgeChunk::STATUS_DISABLED))
            ->when($search !== '', fn (Builder $query) => $this->applySupportSearch($query, $search))
            ->orderBy($sortField, $sortDirection)
            ->orderByDesc('id')
            ->paginate(15, pageName: 'supportPage');
    }

    private function selectedSupportChunk(): ?AiSupportKnowledgeChunk
    {
        if ($this->selectedSupportChunkId === null) {
            return null;
        }

        return AiSupportKnowledgeChunk::find($this->selectedSupportChunkId);
    }

    private function applySupportSearch(Builder $query, string $search): void
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        $pattern = '%' . mb_strtolower($search) . '%';

        $query->where(function (Builder $query) use ($driver, $pattern): void {
            if ($driver === 'pgsql') {
                $query
                    ->whereRaw('LOWER(question) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(answer) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(question_ru, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(answer_ru, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(ai_instruction, '')) LIKE ?", [$pattern])
                    ->orWhereRaw('LOWER(source_hash) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(moderation_reason, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(duplicate_group_key, '')) LIKE ?", [$pattern])
                    ->orWhereRaw('LOWER(keywords::text) LIKE ?', [$pattern]);

                return;
            }

            $query
                ->whereRaw('LOWER(question) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(answer) LIKE ?', [$pattern])
                ->orWhereRaw("LOWER(COALESCE(question_ru, '')) LIKE ?", [$pattern])
                ->orWhereRaw("LOWER(COALESCE(answer_ru, '')) LIKE ?", [$pattern])
                ->orWhereRaw("LOWER(COALESCE(ai_instruction, '')) LIKE ?", [$pattern])
                ->orWhereRaw('LOWER(source_hash) LIKE ?', [$pattern])
                ->orWhereRaw("LOWER(COALESCE(moderation_reason, '')) LIKE ?", [$pattern])
                ->orWhereRaw("LOWER(COALESCE(duplicate_group_key, '')) LIKE ?", [$pattern])
                ->orWhereRaw('LOWER(keywords) LIKE ?', [$pattern]);
        });
    }

    /**
     * @return array{provider: string, model: string, rules_version: string}
     */
    public function moderationInfo(): array
    {
        $settings = app(SettingsService::class);
        $provider = (string) ($settings->get('ai.default_provider') ?? 'deepseek');
        $model = (string) ($settings->get("ai.{$provider}_model") ?? 'не указана');

        return [
            'provider' => $provider !== '' ? $provider : 'deepseek',
            'model' => $model !== '' ? $model : 'не указана',
            'rules_version' => 'KAR-295 / v1 / 03.07.2026',
        ];
    }

    private function normalizeActiveTab(): void
    {
        if (! in_array($this->activeTab, ['support', 'blocks', 'moderation'], true)) {
            $this->activeTab = 'support';
        }
    }

    private function applySearch(Builder $query, string $search): void
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        $pattern = '%' . mb_strtolower($search) . '%';

        $query->where(function (Builder $query) use ($driver, $pattern, $search): void {
            if ($driver === 'pgsql') {
                $query
                    ->whereRaw('LOWER(title) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(content) LIKE ?', [$pattern])
                    ->orWhereJsonContains('keywords', $search)
                    ->orWhereRaw('LOWER(keywords::text) LIKE ?', [$pattern]);

                return;
            }

            $query
                ->whereRaw('LOWER(title) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(slug) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(content) LIKE ?', [$pattern])
                ->orWhereJsonContains('keywords', $search)
                ->orWhereRaw('LOWER(keywords) LIKE ?', [$pattern]);
        });
    }

    private function loadItemIntoDrawer(int $id, string $mode): void
    {
        $this->resetMessages();
        $this->resetValidation();

        $item = AiKnowledgeItem::find($id);
        if ($item === null) {
            $this->errorMessage = 'Блок знаний не найден.';

            return;
        }

        $this->editingId = $item->id;
        $this->drawerMode = $mode;
        $this->form = [
            'title' => $item->title,
            'slug' => $item->slug,
            'content' => $item->content,
            'keywords' => implode(', ', $item->keywords ?? []),
            'priority' => $item->priority,
            'is_active' => (bool) $item->is_active,
        ];
        $this->drawerOpen = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('ai_knowledge_items', 'slug')->ignore($this->editingId),
            ],
            'form.content' => ['required', 'string'],
            'form.keywords' => ['nullable', 'string'],
            'form.priority' => ['required', 'integer', 'min:0', 'max:65535'],
            'form.is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'form.title.required' => 'Введите название.',
            'form.slug.required' => 'Введите slug.',
            'form.slug.regex' => 'Slug может содержать только латиницу, цифры, дефис и подчёркивание.',
            'form.slug.unique' => 'Такой slug уже есть.',
            'form.content.required' => 'Введите текст блока знаний.',
            'form.priority.integer' => 'Приоритет должен быть числом.',
            'form.priority.min' => 'Приоритет не может быть меньше 0.',
            'form.priority.max' => 'Приоритет не может быть больше 65535.',
        ];
    }

    private function loadSupportForm(int $id): void
    {
        $chunk = AiSupportKnowledgeChunk::find($id);
        if ($chunk === null) {
            return;
        }

        $this->supportForm = [
            'question_ru' => (string) ($chunk->question_ru ?? ''),
            'answer_ru' => (string) ($chunk->answer_ru ?? ''),
            'ai_instruction' => (string) ($chunk->ai_instruction ?? ''),
        ];
    }

    private function queueSupportTranslation(AiSupportKnowledgeChunk $chunk, string $field, bool $force): TranslationJob
    {
        $monitor = TranslationJob::create([
            'job_type' => TranslationJob::TYPE_SUPPORT_CASE,
            'subject_type' => AiSupportKnowledgeChunk::class,
            'subject_id' => $chunk->id,
            'subject_label' => 'Support-кейс #' . $chunk->id,
            'source_locale' => $chunk->source_locale ?: 'auto',
            'target_locale' => $chunk->target_locale ?: 'ru',
            'status' => TranslationJob::STATUS_QUEUED,
            'characters' => mb_strlen($chunk->originalQuestion() . $chunk->originalAnswer()),
            'queued_at' => now(),
            'meta' => [
                'field' => $field,
                'source_preview' => mb_substr($chunk->originalQuestion(), 0, 160),
                'force' => $force,
            ],
        ]);

        TranslateSupportCaseJob::dispatch($chunk->id, $field, $force, $monitor->id);

        return $monitor;
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}
