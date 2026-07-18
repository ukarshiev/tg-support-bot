<?php

namespace App\Jobs;

use App\Models\Message;
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

class TranslateMessageHistoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $messageId,
        public readonly int $messageTranslationId,
        public readonly ?int $translationJobId = null,
    ) {
        $this->onQueue('translation');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('message-history:' . $this->messageTranslationId))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(TranslationService $translation): void
    {
        $messageTranslation = MessageTranslation::find($this->messageTranslationId);
        $message = Message::find($this->messageId);
        $monitor = $this->translationJobId !== null ? TranslationJob::find($this->translationJobId) : null;

        if ($messageTranslation === null || $message === null) {
            $monitor?->update([
                'status' => TranslationJob::STATUS_SKIPPED,
                'finished_at' => now(),
                'error_message' => 'Сообщение или запись перевода удалены.',
            ]);

            return;
        }

        $monitor?->update([
            'status' => TranslationJob::STATUS_RUNNING,
            'started_at' => now(),
            'attempts' => ((int) $monitor->attempts) + 1,
        ]);

        $messageTranslation->update([
            'status' => 'running',
            'error_message' => null,
        ]);

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

            return;
        }

        $result = $translation->translate(new TranslationRequest(
            sourceLocale: (string) $messageTranslation->source_locale,
            targetLocale: (string) $messageTranslation->target_locale,
            text: $sourceText,
            purpose: 'chat_history',
        ));

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

            return;
        }

        $error = $result->errorMessage ?? 'Перевод не выполнен.';
        $messageTranslation->update([
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 1024),
        ]);
        $monitor?->update([
            'provider' => $result->provider,
            'status' => TranslationJob::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => mb_substr($error, 0, 1024),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $error = mb_substr($exception?->getMessage() ?: 'Задание перевода завершилось ошибкой.', 0, 1024);

        MessageTranslation::where('id', $this->messageTranslationId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error_message' => $error,
            ]);

        if ($this->translationJobId !== null) {
            TranslationJob::where('id', $this->translationJobId)
                ->update([
                    'status' => TranslationJob::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_message' => $error,
                ]);
        }
    }
}
