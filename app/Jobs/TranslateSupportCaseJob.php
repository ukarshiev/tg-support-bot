<?php

namespace App\Jobs;

use App\Models\AiSupportKnowledgeChunk;
use App\Models\TranslationJob;
use App\Modules\Ai\Support\SupportCaseCanonicalizerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TranslateSupportCaseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $chunkId,
        public readonly string $field = 'all',
        public readonly bool $force = false,
        public readonly ?int $translationJobId = null,
    ) {
        $this->onQueue('translation');
    }

    public function handle(SupportCaseCanonicalizerService $canonicalizer): void
    {
        $chunk = AiSupportKnowledgeChunk::find($this->chunkId);
        $monitor = $this->translationJobId !== null ? TranslationJob::find($this->translationJobId) : null;

        if ($chunk === null) {
            $monitor?->update([
                'status' => TranslationJob::STATUS_SKIPPED,
                'finished_at' => now(),
                'error_message' => 'Support-кейс удалён.',
            ]);

            return;
        }

        $monitor?->update([
            'status' => TranslationJob::STATUS_RUNNING,
            'started_at' => now(),
            'attempts' => ((int) $monitor->attempts) + 1,
        ]);

        $fields = $this->field === 'all' ? ['question', 'answer'] : [$this->field];
        $errors = [];
        $provider = null;

        foreach ($fields as $field) {
            $result = $canonicalizer->canonicalize($chunk->fresh(), $field, $this->force);
            $provider ??= $result->provider;

            if (! $result->success) {
                $errors[] = $field . ': ' . ($result->errorMessage ?? 'ошибка перевода');
            }
        }

        if ($errors !== []) {
            $monitor?->update([
                'provider' => $provider,
                'status' => TranslationJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr(implode('; ', $errors), 0, 1024),
            ]);

            return;
        }

        $monitor?->update([
            'provider' => $provider,
            'status' => TranslationJob::STATUS_DONE,
            'finished_at' => now(),
            'error_message' => null,
        ]);
    }
}
