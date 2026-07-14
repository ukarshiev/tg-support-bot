<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Illuminate\Support\Facades\File;

class SupportEvaluationService
{
    public function __construct(
        private readonly SupportRagSearchService $searchService,
    ) {
    }

    /**
     * @return array{total: int, passed: int, failed: int, cases: array<int, array<string, mixed>>}
     */
    public function run(string $path): array
    {
        if (! File::exists($path)) {
            throw new \RuntimeException("Evaluation file not found: {$path}");
        }

        $cases = json_decode((string) File::get($path), true);
        if (! is_array($cases)) {
            throw new \RuntimeException('Evaluation file must contain JSON array.');
        }

        $results = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }

            $results[] = $this->evaluateCase($case);
        }

        $passed = collect($results)->where('passed', true)->count();

        return [
            'total' => count($results),
            'passed' => $passed,
            'failed' => count($results) - $passed,
            'cases' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array{name: string, query: string, passed: bool, results_count: int, reasons: array<int, string>}
     */
    private function evaluateCase(array $case): array
    {
        $name = (string) ($case['name'] ?? 'unnamed');
        $query = (string) ($case['query'] ?? '');
        $expected = $this->stringList($case['expected_keywords'] ?? []);
        $forbidden = $this->stringList($case['forbidden_keywords'] ?? []);
        $minResults = max(0, (int) ($case['min_results'] ?? 1));

        $results = $this->searchService->search($query, 5);
        $text = mb_strtolower($results->map(
            static fn ($chunk): string => implode("\n", [
                (string) $chunk->question,
                (string) $chunk->answer,
                $chunk->originalQuestion(),
                $chunk->originalAnswer(),
                $chunk->searchableRuQuestion(),
                $chunk->searchableRuAnswer(),
                $chunk->effectiveAiInstruction(),
                implode(' ', $chunk->keywords ?? []),
            ])
        )->implode("\n---\n"));

        $reasons = [];
        if ($results->count() < $minResults) {
            $reasons[] = "Недостаточно результатов: {$results->count()} из {$minResults}.";
        }

        foreach ($expected as $keyword) {
            if (! str_contains($text, mb_strtolower($keyword))) {
                $reasons[] = "Не найден ожидаемый маркер: {$keyword}.";
            }
        }

        foreach ($forbidden as $keyword) {
            if (str_contains($text, mb_strtolower($keyword))) {
                $reasons[] = "Найден запрещённый маркер: {$keyword}.";
            }
        }

        return [
            'name' => $name,
            'query' => $query,
            'passed' => $reasons === [],
            'results_count' => $results->count(),
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value)));
    }
}
