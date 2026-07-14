<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use App\Models\AiSupportKnowledgeChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SupportRagSearchService
{
    public function __construct(private readonly SupportEmbeddingService $embeddingService)
    {
    }

    /**
     * @return Collection<int, AiSupportKnowledgeChunk>
     */
    public function search(string $message, int $limit = 4): Collection
    {
        $terms = $this->terms($message);
        if ($terms === []) {
            return collect();
        }

        $queryEmbedding = $this->embeddingService->embed($message);

        $chunks = AiSupportKnowledgeChunk::query()
            ->where('is_active', true)
            ->where('status', AiSupportKnowledgeChunk::STATUS_ACTIVE)
            ->orderBy('priority')
            ->orderByDesc('first_message_at')
            ->limit(2000)
            ->get();

        $scored = $chunks
            ->map(function (AiSupportKnowledgeChunk $chunk) use ($terms, $queryEmbedding): array {
                $lexical = $this->lexicalScore($chunk, $terms);
                $vector = $queryEmbedding !== null ? $this->vectorScore($queryEmbedding, $chunk->embedding) : 0.0;
                $instructionBoost = trim((string) $chunk->ai_instruction) !== '' ? 0.2 : 0.0;

                return [
                    'chunk' => $chunk,
                    'score' => $lexical + $vector + $instructionBoost,
                    'lexical' => $lexical,
                    'vector' => $vector,
                ];
            })
            ->filter(static fn (array $row): bool => $row['score'] > 0);

        $result = $scored
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('chunk')
            ->values();

        if ($result->isNotEmpty()) {
            Log::channel('app')->info('AI support RAG context selected', [
                'source' => 'ai_support_rag',
                'chunks' => $result->pluck('source_hash')->values()->all(),
            ]);
        }

        return $result;
    }

    /**
     * @param array<int, string> $terms
     */
    private function lexicalScore(AiSupportKnowledgeChunk $chunk, array $terms): float
    {
        $ruHaystack = mb_strtolower($chunk->searchableRuQuestion() . ' ' . $chunk->searchableRuAnswer());
        $originalHaystack = mb_strtolower($chunk->originalQuestion() . ' ' . $chunk->originalAnswer());
        $legacyHaystack = mb_strtolower((string) $chunk->question . ' ' . (string) $chunk->answer);
        $keywords = collect($chunk->keywords ?? [])
            ->map(static fn (mixed $keyword): string => mb_strtolower((string) $keyword))
            ->all();

        $score = 0.0;
        foreach ($terms as $term) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && ($keyword === $term || str_contains($keyword, $term) || str_contains($term, $keyword))) {
                    $score += 4.0;
                }
            }

            if (str_contains($ruHaystack, $term)) {
                $score += 3.0;
            }

            if (str_contains($originalHaystack, $term)) {
                $score += 2.0;
            }

            if (str_contains($legacyHaystack, $term)) {
                $score += 1.0;
            }
        }

        $requestedProducts = array_values(array_intersect($terms, ['brospace', 'elite', 'massage']));
        if ($requestedProducts !== []) {
            $haystack = $ruHaystack . ' ' . $originalHaystack . ' ' . $legacyHaystack . ' ' . implode(' ', $keywords);
            foreach (['brospace', 'elite', 'massage'] as $product) {
                if (! in_array($product, $requestedProducts, true) && str_contains($haystack, $product)) {
                    return 0.0;
                }
            }
        }

        return $score;
    }

    /**
     * @param array<int, float>      $queryEmbedding
     * @param array<int, float>|null $chunkEmbedding
     */
    private function vectorScore(array $queryEmbedding, ?array $chunkEmbedding): float
    {
        if ($chunkEmbedding === null || $chunkEmbedding === [] || count($queryEmbedding) !== count($chunkEmbedding)) {
            return 0.0;
        }

        $dot = 0.0;
        $queryNorm = 0.0;
        $chunkNorm = 0.0;

        foreach ($queryEmbedding as $index => $value) {
            $other = (float) ($chunkEmbedding[$index] ?? 0.0);
            $dot += $value * $other;
            $queryNorm += $value * $value;
            $chunkNorm += $other * $other;
        }

        if ($queryNorm <= 0.0 || $chunkNorm <= 0.0) {
            return 0.0;
        }

        $cosine = $dot / (sqrt($queryNorm) * sqrt($chunkNorm));

        return $cosine >= 0.72 ? $cosine * 10.0 : 0.0;
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $message): array
    {
        preg_match_all('/[\p{L}\p{N}_-]{3,}/u', mb_strtolower($message), $matches);

        return collect($matches[0])
            ->reject(static fn (string $term): bool => in_array($term, [
                'the',
                'and',
                'how',
                'much',
                'what',
                'where',
                'price',
                'cost',
                'costs',
                'tariff',
                'that',
                'this',
                'что',
                'как',
                'для',
                'это',
                'или',
                'где',
                'купить',
                'сколько',
                'стоит',
                'цена',
                'тариф',
            ], true))
            ->unique()
            ->values()
            ->all();
    }
}
