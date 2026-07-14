<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\AiKnowledgeItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AiKnowledgeService
{
    private const MAX_ITEMS = 3;

    private const MAX_CONTEXT_CHARS = 4000;

    /**
     * Build a compact system message with only knowledge blocks relevant to
     * the current user message.
     *
     * @return array{role: string, content: string}|null
     */
    public function buildContextMessage(string $message): ?array
    {
        $items = $this->findRelevant($message);
        if ($items->isEmpty()) {
            return null;
        }

        $content = "Релевантные данные базы знаний. Используй только если они помогают ответить на вопрос клиента. Не выдумывай данные вне этих блоков.\n\n";

        foreach ($items as $item) {
            $content .= "### {$item->title}\n";
            $content .= trim($item->content) . "\n\n";
        }

        $content = mb_substr(trim($content), 0, self::MAX_CONTEXT_CHARS);

        Log::channel('app')->info('AI knowledge context selected', [
            'source' => 'ai_knowledge',
            'items' => $items->pluck('slug')->values()->all(),
            'chars' => mb_strlen($content),
        ]);

        return [
            'role' => 'system',
            'content' => $content,
        ];
    }

    /**
     * @return Collection<int, AiKnowledgeItem>
     */
    public function findRelevant(string $message): Collection
    {
        $terms = $this->extractTerms($message);
        if ($terms === []) {
            return collect();
        }

        $scored = AiKnowledgeItem::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(fn (AiKnowledgeItem $item): array => [
                'item' => $item,
                'score' => $this->score($item, $terms),
            ])
            ->filter(fn (array $row): bool => $row['score'] > 0);

        if ($scored->isEmpty()) {
            return collect();
        }

        $maxScore = (int) $scored->max('score');
        if ($maxScore >= 4) {
            $scored = $scored->filter(fn (array $row): bool => $row['score'] >= 4);
        }

        return $scored
            ->sortByDesc('score')
            ->take(self::MAX_ITEMS)
            ->pluck('item')
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function extractTerms(string $message): array
    {
        $normalized = mb_strtolower($message);
        preg_match_all('/[\p{L}\p{N}_-]{3,}/u', $normalized, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param array<int, string> $terms
     */
    private function score(AiKnowledgeItem $item, array $terms): int
    {
        $score = 0;
        $title = mb_strtolower($item->title);
        $slug = mb_strtolower($item->slug);
        $content = mb_strtolower($item->content);
        $keywords = collect($item->keywords ?? [])
            ->map(fn (mixed $keyword): string => mb_strtolower((string) $keyword))
            ->all();

        foreach ($terms as $term) {
            if (str_contains($slug, $term)) {
                $score += 6;
            }

            if (str_contains($title, $term)) {
                $score += 5;
            }

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && (str_contains($keyword, $term) || str_contains($term, $keyword))) {
                    $score += 4;
                }
            }

            if (str_contains($content, $term)) {
                $score += 1;
            }
        }

        return $score;
    }
}
