<?php

namespace App\Jobs;

use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\TranslationJob;
use App\Modules\Translation\DTOs\TranslationRequest;
use App\Modules\Translation\Services\TranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TranslateAutoReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $autoReplyId,
        public readonly string $locale,
        public readonly bool $overwriteManual = false,
        public readonly ?int $translationJobId = null,
    ) {
        $this->onQueue('translation');
    }

    public function handle(TranslationService $translation): void
    {
        $monitor = $this->monitorJob();
        $monitor?->fill([
            'status' => TranslationJob::STATUS_RUNNING,
            'started_at' => $monitor->started_at ?? now(),
            'attempts' => ((int) $monitor->attempts) + 1,
            'error_message' => null,
        ])->save();

        $autoReply = AutoReply::find($this->autoReplyId);
        if ($autoReply === null || trim($autoReply->response) === '') {
            $monitor?->fill([
                'status' => TranslationJob::STATUS_SKIPPED,
                'finished_at' => now(),
                'error_message' => 'Автоответ не найден или пустой.',
            ])->save();
            return;
        }

        $sourceHash = AutoReply::sourceHash($autoReply->response);
        $existing = AutoReplyTranslation::firstOrNew([
            'auto_reply_id' => $autoReply->id,
            'locale' => $this->locale,
        ]);

        if ($existing->exists && $existing->source === AutoReplyTranslation::SOURCE_MANUAL && !$this->overwriteManual) {
            $monitor?->fill([
                'status' => TranslationJob::STATUS_SKIPPED,
                'finished_at' => now(),
                'error_message' => 'Ручной перевод не перезаписан.',
            ])->save();
            return;
        }

        $existing->fill([
            'status' => 'translating',
            'source_hash' => $sourceHash,
        ])->save();

        $result = $translation->translate(new TranslationRequest(
            sourceLocale: 'ru',
            targetLocale: $this->locale,
            text: $autoReply->response,
            purpose: 'auto_reply',
        ));

        if (!$result->success || !is_string($result->text)) {
            $existing->fill([
                'status' => AutoReplyTranslation::STATUS_ERROR,
                'provider' => $result->provider,
                'source_hash' => $sourceHash,
            ])->save();
            $monitor?->fill([
                'status' => TranslationJob::STATUS_FAILED,
                'provider' => $result->provider,
                'finished_at' => now(),
                'characters' => mb_strlen($autoReply->response),
                'error_message' => $result->errorMessage ?? $result->errorCode ?? 'Перевод не выполнен.',
            ])->save();
            return;
        }

        $existing->fill([
            'text' => $result->text,
            'status' => AutoReplyTranslation::STATUS_READY,
            'source' => AutoReplyTranslation::SOURCE_AUTO,
            'provider' => $result->provider,
            'source_hash' => $sourceHash,
            'translated_at' => now(),
        ])->save();

        $monitor?->fill([
            'status' => TranslationJob::STATUS_DONE,
            'provider' => $result->provider,
            'finished_at' => now(),
            'characters' => mb_strlen($autoReply->response),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        $this->monitorJob()?->fill([
            'status' => TranslationJob::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => mb_substr($exception->getMessage(), 0, 1024),
        ])->save();
    }

    private function monitorJob(): ?TranslationJob
    {
        if ($this->translationJobId === null) {
            return null;
        }

        return TranslationJob::find($this->translationJobId);
    }
}
