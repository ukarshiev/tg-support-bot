<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AiKnowledgeItem;
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

    public bool $drawerOpen = false;

    public string $drawerMode = 'view';

    public ?int $editingId = null;

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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
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

        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetMessages();
        $this->resetValidation();
        $this->editingId = null;
        $this->drawerMode = 'create';
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
        $this->resetPage();
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
        $this->resetPage();
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
        ]);
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
            ->paginate(10);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $driver = $query->getConnection()->getDriverName();
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

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }
}
