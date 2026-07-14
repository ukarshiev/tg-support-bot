<?php

namespace App\Console\Commands;

use App\Jobs\TranslateSupportCaseJob;
use App\Models\AiSupportKnowledgeChunk;
use App\Models\TranslationJob;
use App\Modules\Ai\Support\SupportCaseCanonicalizerService;
use Illuminate\Console\Command;

class AiSupportCanonicalize extends Command
{
    protected $signature = 'ai:support-canonicalize {--limit=100 : Сколько кейсов взять} {--field=all : question, answer или all} {--force : Перезаписать даже ручные RU-правки} {--sync : Выполнить сразу без очереди}';

    protected $description = 'Queue or run RU canonical translation for support RAG cases.';

    public function handle(SupportCaseCanonicalizerService $canonicalizer): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $field = (string) $this->option('field');
        if (! in_array($field, ['question', 'answer', 'all'], true)) {
            $this->error('Поле должно быть question, answer или all.');

            return self::FAILURE;
        }

        $fields = $field === 'all' ? ['question', 'answer'] : [$field];
        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $queued = 0;
        $done = 0;
        $failed = 0;

        $query = AiSupportKnowledgeChunk::query()
            ->where(function ($query) use ($fields, $force): void {
                foreach ($fields as $item) {
                    $query->orWhere(function ($nested) use ($item, $force): void {
                        $nested->where(function ($statusQuery) use ($item): void {
                            $statusQuery
                                ->whereNull($item . '_ru')
                                ->orWhere($item . '_ru', '')
                                ->orWhereIn($item . '_translation_status', [
                                    AiSupportKnowledgeChunk::TRANSLATION_PENDING,
                                    AiSupportKnowledgeChunk::TRANSLATION_FAILED,
                                    AiSupportKnowledgeChunk::TRANSLATION_NEEDS_REVIEW,
                                ]);
                        });

                        if (! $force) {
                            $nested->where($item . '_ru_manually_edited', false);
                        }
                    });
                }
            })
            ->orderBy('id')
            ->limit($limit);

        foreach ($query->get() as $chunk) {
            if ($sync) {
                foreach ($fields as $item) {
                    $result = $canonicalizer->canonicalize($chunk->fresh(), $item, $force);
                    $result->success ? $done++ : $failed++;
                }

                continue;
            }

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
            $queued++;
        }

        $this->info("Support RU canonical: queued={$queued}, done={$done}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
