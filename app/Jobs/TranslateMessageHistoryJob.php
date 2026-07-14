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
use Illuminate\Queue\SerializesModels;

class TranslateMessageHistoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $messageId,
        public readonly int $messageTranslationId,
        public readonly ?int $translationJobId = null,
    ) {
        $this->onQueue('translation');
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
}
