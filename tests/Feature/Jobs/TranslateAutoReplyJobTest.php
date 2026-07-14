<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\TranslateAutoReplyJob;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\TranslationJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslateAutoReplyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_translates_auto_reply_with_configured_provider(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $reply = AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => 'start',
            'response' => 'Добрый день!',
            'enabled' => true,
        ]);

        (new TranslateAutoReplyJob($reply->id, 'en'))->handle(app(\App\Modules\Translation\Services\TranslationService::class));

        $translation = AutoReplyTranslation::where('auto_reply_id', $reply->id)->where('locale', 'en')->firstOrFail();
        $this->assertSame(AutoReplyTranslation::STATUS_READY, $translation->status);
        $this->assertSame(AutoReplyTranslation::SOURCE_AUTO, $translation->source);
        $this->assertSame('fake', $translation->provider);
        $this->assertSame('[en] Добрый день!', $translation->text);
    }

    public function test_it_does_not_overwrite_manual_translation_without_permission(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $reply = AutoReply::create(['trigger' => 'start', 'response' => 'Добрый день!']);
        AutoReplyTranslation::create([
            'auto_reply_id' => $reply->id,
            'locale' => 'en',
            'text' => 'Manual text',
            'status' => AutoReplyTranslation::STATUS_READY,
            'source' => AutoReplyTranslation::SOURCE_MANUAL,
        ]);

        (new TranslateAutoReplyJob($reply->id, 'en'))->handle(app(\App\Modules\Translation\Services\TranslationService::class));

        $translation = AutoReplyTranslation::where('auto_reply_id', $reply->id)->where('locale', 'en')->firstOrFail();
        $this->assertSame('Manual text', $translation->text);
        $this->assertSame(AutoReplyTranslation::SOURCE_MANUAL, $translation->source);
    }

    public function test_it_updates_translation_job_monitoring_status(): void
    {
        app(SettingsService::class)->set('translation.provider_order', ['fake']);

        $reply = AutoReply::create(['trigger' => 'start', 'response' => 'Добрый день!']);
        $monitor = TranslationJob::create([
            'job_type' => TranslationJob::TYPE_AUTO_REPLY,
            'subject_type' => AutoReply::class,
            'subject_id' => $reply->id,
            'subject_label' => 'Автоответ: start',
            'source_locale' => 'ru',
            'target_locale' => 'en',
            'status' => TranslationJob::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        (new TranslateAutoReplyJob($reply->id, 'en', false, $monitor->id))
            ->handle(app(\App\Modules\Translation\Services\TranslationService::class));

        $monitor->refresh();
        $this->assertSame(TranslationJob::STATUS_DONE, $monitor->status);
        $this->assertSame('fake', $monitor->provider);
        $this->assertSame(1, $monitor->attempts);
        $this->assertNotNull($monitor->started_at);
        $this->assertNotNull($monitor->finished_at);
    }
}
