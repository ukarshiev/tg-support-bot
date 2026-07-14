<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Ai\Support\SupportEvaluationService;
use Illuminate\Console\Command;

class AiSupportEvaluate extends Command
{
    protected $signature = 'ai:support-evaluate
        {file=resources/ai/support-evaluation.json : Evaluation JSON file}';

    protected $description = 'Run support RAG evaluation checks.';

    public function handle(SupportEvaluationService $service): int
    {
        $file = (string) $this->argument('file');
        $path = str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $file) === 1
            ? $file
            : base_path($file);
        $result = $service->run($path);

        $this->info('Support RAG evaluation complete.');
        $this->line('Total: ' . $result['total']);
        $this->line('Passed: ' . $result['passed']);
        $this->line('Failed: ' . $result['failed']);

        foreach ($result['cases'] as $case) {
            $status = $case['passed'] ? 'PASS' : 'FAIL';
            $this->line("[{$status}] {$case['name']} — results: {$case['results_count']}");

            foreach ($case['reasons'] as $reason) {
                $this->line('  - ' . $reason);
            }
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
