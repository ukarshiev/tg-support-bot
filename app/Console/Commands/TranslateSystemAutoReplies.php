<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TranslateAutoReplyJob;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\TranslationJob;
use App\Modules\Translation\Services\SupportLanguageSettings;
use Illuminate\Console\Command;

class TranslateSystemAutoReplies extends Command
{
    protected $signature = 'auto-replies:translate-system {--overwrite-manual : Перезаписать ручные переводы}';

    protected $description = 'Ставит отсутствующие и устаревшие переводы системных автоответов в очередь';

    public function handle(SupportLanguageSettings $languages): int
    {
        $queued = 0;
        $overwriteManual = (bool) $this->option('overwrite-manual');

        $replies = AutoReply::query()
            ->whereIn('trigger', array_values(AutoReply::systemTriggers()))
            ->where('enabled', true)
            ->get();

        foreach ($replies as $reply) {
            $sourceHash = AutoReply::sourceHash($reply->response);
            foreach ($languages->enabledLanguages() as $locale => $language) {
                if ($locale === 'ru') {
                    continue;
                }

                $translation = AutoReplyTranslation::query()
                    ->where('auto_reply_id', $reply->id)
                    ->where('locale', $locale)
                    ->first();

                if ($translation?->status === AutoReplyTranslation::STATUS_READY
                    && hash_equals((string) $translation->source_hash, $sourceHash)
                ) {
                    continue;
                }

                if (!$overwriteManual && $translation?->source === AutoReplyTranslation::SOURCE_MANUAL) {
                    continue;
                }

                $monitor = TranslationJob::create([
                    'job_type' => TranslationJob::TYPE_AUTO_REPLY,
                    'subject_type' => AutoReply::class,
                    'subject_id' => $reply->id,
                    'subject_label' => (AutoReply::typeLabels()[$reply->type] ?? 'Автоответ') . ': ' . $reply->trigger,
                    'source_locale' => 'ru',
                    'target_locale' => (string) $locale,
                    'status' => TranslationJob::STATUS_QUEUED,
                    'characters' => mb_strlen($reply->response),
                    'queued_at' => now(),
                    'meta' => ['system' => true, 'language' => $language],
                ]);

                TranslateAutoReplyJob::dispatch($reply->id, (string) $locale, $overwriteManual, $monitor->id);
                $queued++;
            }
        }

        $this->info("Поставлено переводов в очередь: {$queued}");

        return self::SUCCESS;
    }
}
