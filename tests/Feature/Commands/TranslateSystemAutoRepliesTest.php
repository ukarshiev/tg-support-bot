<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\TranslateAutoReplyJob;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Modules\Translation\Services\SupportLanguageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TranslateSystemAutoRepliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_ready_translation_when_placeholders_are_corrupted(): void
    {
        Queue::fake();

        $languages = Mockery::mock(SupportLanguageSettings::class);
        $languages->shouldReceive('enabledLanguages')->once()->andReturn([
            'ru' => $this->language('ru'),
            'ar' => $this->language('ar'),
        ]);
        $this->app->instance(SupportLanguageSettings::class, $languages);

        $reply = AutoReply::query()
            ->where('type', AutoReply::TYPE_WELCOME)
            ->where('trigger', AutoReply::TRIGGER_WELCOME)
            ->firstOrFail();
        $reply->update(['response' => 'Коннектор — {{connector}}']);
        AutoReply::query()->whereKeyNot($reply->id)->update(['enabled' => false]);

        AutoReplyTranslation::updateOrCreate(
            [
                'auto_reply_id' => $reply->id,
                'locale' => 'ar',
            ],
            [
                'text' => 'موصل — {{موصل}}',
                'status' => AutoReplyTranslation::STATUS_READY,
                'source' => AutoReplyTranslation::SOURCE_AUTO,
                'source_hash' => AutoReply::sourceHash($reply->response),
            ],
        );

        $this->artisan('auto-replies:translate-system')
            ->expectsOutput('Поставлено переводов в очередь: 1')
            ->assertSuccessful();

        Queue::assertPushed(
            TranslateAutoReplyJob::class,
            fn (TranslateAutoReplyJob $job): bool => $job->autoReplyId === $reply->id && $job->locale === 'ar',
        );
    }

    /**
     * @return array{code: string, name: string, native: string, enabled: bool, show_on_start: bool, sort_order: int}
     */
    private function language(string $code): array
    {
        return [
            'code' => $code,
            'name' => $code,
            'native' => $code,
            'enabled' => true,
            'show_on_start' => true,
            'sort_order' => 1,
        ];
    }
}
