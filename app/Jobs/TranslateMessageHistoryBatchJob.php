<?php

namespace App\Jobs;

use App\Models\MessageTranslation;
use App\Models\TranslationJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TranslateMessageHistoryBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    /**
     * @param array<int, int> $messageTranslationIds
     * @param array<int, int> $translationJobIds
     */
    public function __construct(
        public readonly array $messageTranslationIds,
        public readonly array $translationJobIds = [],
    ) {
        $this->onQueue('translation');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $ids = $this->messageTranslationIds;
        sort($ids);

        return [
            (new WithoutOverlapping('message-history-batch:' . sha1(implode(',', $ids))))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(TranslationService $translation): void
    {
        $items = MessageTranslation::query()
            ->whereIn('id', $this->messageTranslationIds)
            ->whereIn('status', ['queued', 'running'])
            ->get()
            ->keyBy('id');

        $monitors = TranslationJob::query()
            ->whereIn('id', $this->translationJobIds)
            ->get()
            ->keyBy('id');

        $requests = [];
        $translationByIndex = [];
        $monitorByTranslationId = [];

        foreach ($this->messageTranslationIds as $position => $translationId) {
            /** @var MessageTranslation|null $messageTranslation */
            $messageTranslation = $items->get($translationId);
            /** @var TranslationJob|null $monitor */
            $monitor = isset($this->translationJobIds[$position])
                ? $monitors->get($this->translationJobIds[$position])
                : null;

            if ($messageTranslation === null) {
                $monitor?->update([
                    'status' => TranslationJob::STATUS_SKIPPED,
                    'finished_at' => now(),
                    'error_message' => 'Запись перевода удалена.',
                ]);

                continue;
            }

            $monitorByTranslationId[$messageTranslation->id] = $monitor;
            $sourceText = trim((string) $messageTranslation->source_text);

            if ($sourceText === '') {
                $messageTranslation->update([
                    'status' => 'skipped',
                    'error_message' => 'Нет текста для перевода.',
                ]);
                $monitor?->update([
                    'status' => TranslationJob::STATUS_SKIPPED,
                    'finished_at' => now(),
                    'error_message' => 'Нет текста для перевода.',
                ]);

                continue;
            }

            $messageTranslation->update([
                'status' => 'running',
                'error_message' => null,
            ]);
            $monitor?->update([
                'status' => TranslationJob::STATUS_RUNNING,
                'started_at' => now(),
                'attempts' => ((int) $monitor->attempts) + 1,
            ]);

            $requests[] = new TranslationRequest(
                sourceLocale: (string) $messageTranslation->source_locale,
                targetLocale: (string) $messageTranslation->target_locale,
                text: $sourceText,
                purpose: 'chat_history',
            );
            $translationByIndex[array_key_last($requests)] = $messageTranslation;
        }

        if ($requests === []) {
            return;
        }

        $results = $translation->translateMany($requests);

        foreach ($results as $index => $result) {
            /** @var MessageTranslation|null $messageTranslation */
            $messageTranslation = $translationByIndex[$index] ?? null;
            if ($messageTranslation === null) {
                continue;
            }

            /** @var TranslationJob|null $monitor */
            $monitor = $monitorByTranslationId[$messageTranslation->id] ?? null;

            if ($result->success) {
                $messageTranslation->update([
                    'translated_text' => $result->text,
                    'status' => 'ready',
                    'provider' => $result->provider,
                    'error_message' => null,
                    'translated_at' => now(),
                ]);
                $monitor?->update([
                    'provider' => $result->provider,
                    'status' => TranslationJob::STATUS_DONE,
                    'finished_at' => now(),
                    'error_message' => null,
                ]);

                continue;
            }

            $error = mb_substr($result->errorMessage ?? 'Перевод не выполнен.', 0, 1024);
            $messageTranslation->update([
                'status' => 'failed',
                'provider' => $result->provider,
                'error_message' => $error,
            ]);
            $monitor?->update([
                'provider' => $result->provider,
                'status' => TranslationJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $error,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = mb_substr($exception?->getMessage() ?: 'Пачечный перевод завершился ошибкой.', 0, 1024);

        MessageTranslation::whereIn('id', $this->messageTranslationIds)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => $error,
            ]);

        TranslationJob::whereIn('id', $this->translationJobIds)
            ->update([
                'status' => TranslationJob::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $error,
            ]);
    }
}
