<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiKnowledgeItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AiKnowledgeImport extends Command
{
    protected $signature = 'ai:knowledge-import {file : Path to JSON file with knowledge items}';

    protected $description = 'Import or update AI knowledge blocks from a JSON file.';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $items = json_decode((string) File::get($path), true);
        if (! is_array($items)) {
            $this->error('JSON must contain an array of knowledge items.');

            return self::FAILURE;
        }

        $count = 0;
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $content = trim((string) ($row['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }

            $slug = trim((string) ($row['slug'] ?? Str::slug($title)));
            if ($slug === '') {
                $slug = 'knowledge-' . substr(sha1($title), 0, 12);
            }

            AiKnowledgeItem::updateOrCreate(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'content' => $content,
                    'keywords' => array_values((array) ($row['keywords'] ?? [])),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'priority' => (int) ($row['priority'] ?? 100),
                ]
            );

            $count++;
        }

        $this->info("Imported knowledge items: {$count}");

        return self::SUCCESS;
    }
}
