<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\TranslateMessageHistoryBatchJob;
use App\Models\BotUser;
use App\Models\Message;
use App\Models\MessageTranslation;
use App\Models\TranslationJob;
use App\Modules\Translation\DTOs\TranslationResult;
use App\Modules\Translation\Services\TranslationService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TranslateMessageHistoryBatchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_job_translates_many_history_messages_and_updates_monitors(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $botUser = BotUser::create(['chat_id' => 501, 'platform' => 'telegram']);
        $first = $this->createQueuedTranslation($botUser, 'Merhaba');
        $second = $this->createQueuedTranslation($botUser, 'Nasılsın?');

        $job = new TranslateMessageHistoryBatchJob(
            [$first['translation']->id, $second['translation']->id],
            [$first['monitor']->id, $second['monitor']->id],
        );
        $job->handle(app(TranslationService::class));

        $this->assertDatabaseHas('message_translations', [
            'id' => $first['translation']->id,
            'status' => 'ready',
            'translated_text' => '[ru] Merhaba',
            'provider' => 'fake',
        ]);
        $this->assertDatabaseHas('message_translations', [
            'id' => $second['translation']->id,
            'status' => 'ready',
            'translated_text' => '[ru] Nasılsın?',
            'provider' => 'fake',
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'id' => $first['monitor']->id,
            'status' => TranslationJob::STATUS_DONE,
            'provider' => 'fake',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'id' => $second['monitor']->id,
            'status' => TranslationJob::STATUS_DONE,
            'provider' => 'fake',
            'attempts' => 1,
        ]);
    }

    public function test_batch_job_marks_failed_item_without_breaking_successful_items(): void
    {
        $botUser = BotUser::create(['chat_id' => 502, 'platform' => 'telegram']);
        $first = $this->createQueuedTranslation($botUser, 'Merhaba');
        $second = $this->createQueuedTranslation($botUser, 'Bozuk');

        $service = Mockery::mock(TranslationService::class);
        $service->shouldReceive('translateMany')
            ->once()
            ->andReturn([
                0 => TranslationResult::success('[ru] Merhaba', 'fake'),
                1 => TranslationResult::failure('provider_error', 'Провайдер упал только на этом тексте.', 'fake'),
            ]);

        $job = new TranslateMessageHistoryBatchJob(
            [$first['translation']->id, $second['translation']->id],
            [$first['monitor']->id, $second['monitor']->id],
        );
        $job->handle($service);

        $this->assertDatabaseHas('message_translations', [
            'id' => $first['translation']->id,
            'status' => 'ready',
            'translated_text' => '[ru] Merhaba',
        ]);
        $this->assertDatabaseHas('message_translations', [
            'id' => $second['translation']->id,
            'status' => 'failed',
            'provider' => 'fake',
            'error_message' => 'Провайдер упал только на этом тексте.',
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'id' => $first['monitor']->id,
            'status' => TranslationJob::STATUS_DONE,
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'id' => $second['monitor']->id,
            'status' => TranslationJob::STATUS_FAILED,
        ]);
    }

    /**
     * @return array{translation: MessageTranslation, monitor: TranslationJob}
     */
    private function createQueuedTranslation(BotUser $botUser, string $text): array
    {
        $message = Message::create([
            'bot_user_id' => $botUser->id,
            'platform' => 'telegram',
            'message_type' => 'incoming',
            'from_id' => 501,
            'to_id' => 0,
            'text' => $text,
        ]);

        $translation = MessageTranslation::create([
            'message_id' => $message->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'source_text' => $text,
            'direction' => 'client_to_operator',
            'status' => 'queued',
            'source' => 'auto',
            'source_hash' => TranslationService::sourceHash($text),
        ]);

        $monitor = TranslationJob::create([
            'job_type' => TranslationJob::TYPE_MESSAGE_HISTORY,
            'subject_type' => Message::class,
            'subject_id' => $message->id,
            'subject_label' => 'Сообщение #' . $message->id,
            'source_locale' => 'tr',
            'target_locale' => 'ru',
            'status' => TranslationJob::STATUS_QUEUED,
            'characters' => mb_strlen($text),
            'queued_at' => now(),
            'meta' => ['message_translation_id' => $translation->id],
        ]);

        return ['translation' => $translation, 'monitor' => $monitor];
    }
}
