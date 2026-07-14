<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiSupportKnowledgeChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AiSupportExportFineTune extends Command
{
    protected $signature = 'ai:support-export-finetune {file : Output JSONL file path}';

    protected $description = 'Export active support RAG chunks to OpenAI chat fine-tuning JSONL.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $directory = dirname($path);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $system = 'Ты AI-ассистент службы поддержки RelaxaClub. Отвечай кратко, вежливо и только по проверенным данным. Если точных данных нет, передай вопрос специалисту.';
        $count = 0;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error("Cannot write file: {$path}");

            return self::FAILURE;
        }

        AiSupportKnowledgeChunk::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->chunk(200, function ($chunks) use ($handle, $system, &$count): void {
                foreach ($chunks as $chunk) {
                    $row = [
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $chunk->question],
                            ['role' => 'assistant', 'content' => $chunk->answer],
                        ],
                    ];

                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    $count++;
                }
            });

        fclose($handle);

        $this->info("Exported fine-tuning examples: {$count}");
        $this->line($path);

        return self::SUCCESS;
    }
}
