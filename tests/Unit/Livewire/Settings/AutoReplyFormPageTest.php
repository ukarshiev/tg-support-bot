<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Jobs\TranslateAutoReplyJob;
use App\Livewire\Settings\AutoReplyFormPage;
use App\Models\AutoReply;
use App\Models\AutoReplyTranslation;
use App\Models\AutoReplyVariable;
use App\Models\TranslationJob;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AutoReplyFormPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_mode_renders_blank_form_with_new_title(): void
    {
        Livewire::test(AutoReplyFormPage::class)
            ->assertSet('trigger', '')
            ->assertSet('response', '')
            ->assertSet('ruleId', null)
            ->assertSee('Новый автоответ')
            ->assertSee('Параметры правила');
    }

    public function test_edit_mode_prefills_fields_from_the_database(): void
    {
        $rule = AutoReply::create(['trigger' => 'Привет', 'response' => 'Здравствуйте!', 'enabled' => true]);

        Livewire::test(AutoReplyFormPage::class, ['rule' => $rule->id])
            ->assertSet('ruleId', $rule->id)
            ->assertSet('trigger', 'Привет')
            ->assertSet('response', 'Здравствуйте!')
            ->assertSet('enabled', true)
            ->assertSee('Редактирование автоответа');
    }

    public function test_unknown_id_falls_back_to_create_mode(): void
    {
        Livewire::test(AutoReplyFormPage::class, ['rule' => 999])
            ->assertSet('ruleId', null)
            ->assertSet('trigger', '');
    }

    public function test_save_creates_a_new_rule_and_redirects(): void
    {
        Livewire::test(AutoReplyFormPage::class)
            ->set('trigger', 'Доставка')
            ->set('response', 'Доставка 3-5 дней.')
            ->set('enabled', true)
            ->call('save')
            ->assertRedirect(route('admin.settings.auto-replies'));

        $this->assertDatabaseHas('auto_replies', [
            'trigger' => 'Доставка',
            'response' => 'Доставка 3-5 дней.',
            'type' => AutoReply::TYPE_REGULAR,
            'source_locale' => 'ru',
            'enabled' => true,
        ]);
    }

    public function test_save_updates_an_existing_rule_without_creating_a_duplicate(): void
    {
        $rule = AutoReply::create(['trigger' => 'Привет', 'response' => 'Старый текст', 'enabled' => true]);

        Livewire::test(AutoReplyFormPage::class, ['rule' => $rule->id])
            ->set('response', 'Новый текст')
            ->set('enabled', false)
            ->call('save')
            ->assertRedirect(route('admin.settings.auto-replies'));

        $this->assertDatabaseHas('auto_replies', [
            'id' => $rule->id,
            'response' => 'Новый текст',
            'enabled' => false,
        ]);
        $this->assertSame(1, AutoReply::query()->where('type', AutoReply::TYPE_REGULAR)->count());
    }

    public function test_save_requires_trigger_and_response(): void
    {
        Livewire::test(AutoReplyFormPage::class)
            ->set('trigger', '')
            ->set('response', '')
            ->call('save')
            ->assertHasErrors(['trigger', 'response']);

        $this->assertSame(0, AutoReply::query()->where('type', AutoReply::TYPE_REGULAR)->count());
    }

    public function test_save_supports_service_reply_types(): void
    {
        $existing = AutoReply::query()->where('type', AutoReply::TYPE_FEEDBACK_REQUEST)->firstOrFail();

        Livewire::test(AutoReplyFormPage::class, ['rule' => $existing->id])
            ->set('type', AutoReply::TYPE_FEEDBACK_REQUEST)
            ->set('trigger', 'произвольный триггер')
            ->set('response', 'Добрый день!')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.settings.auto-replies'));

        $this->assertDatabaseHas('auto_replies', [
            'id' => $existing->id,
            'type' => AutoReply::TYPE_FEEDBACK_REQUEST,
            'trigger' => AutoReply::TRIGGER_FEEDBACK_REQUEST,
            'response' => 'Добрый день!',
            'source_locale' => 'ru',
        ]);
    }

    public function test_cannot_create_duplicate_system_reply_type(): void
    {
        Livewire::test(AutoReplyFormPage::class)
            ->set('type', AutoReply::TYPE_DIALOG_CLOSED)
            ->set('trigger', 'duplicate')
            ->set('response', 'Дубликат')
            ->call('save')
            ->assertHasErrors(['type']);

        $this->assertSame(1, AutoReply::query()->where('type', AutoReply::TYPE_DIALOG_CLOSED)->count());
    }

    public function test_translate_all_languages_queues_jobs_and_notifies_operator(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'tr' => ['code' => 'tr', 'name' => 'Türkçe', 'native' => '🇹🇷 Türkçe', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 3],
            'de' => ['code' => 'de', 'name' => 'Deutsch', 'native' => '🇩🇪 Deutsch', 'enabled' => false, 'show_on_start' => true, 'sort_order' => 4],
        ]);

        $rule = AutoReply::create([
            'type' => AutoReply::TYPE_WELCOME,
            'trigger' => '__system_welcome__',
            'response' => 'Добрый день!',
            'enabled' => true,
        ]);

        Livewire::test(AutoReplyFormPage::class, ['rule' => $rule->id])
            ->call('translateAllLanguages')
            ->assertDispatched('admin-toast', message: 'Перевод поставлен в очередь: 2 языков.', type: 'success');

        Queue::assertPushed(TranslateAutoReplyJob::class, 2);
        Queue::assertPushed(TranslateAutoReplyJob::class, fn (TranslateAutoReplyJob $job): bool => $job->locale === 'en' && $job->translationJobId !== null);
        Queue::assertPushed(TranslateAutoReplyJob::class, fn (TranslateAutoReplyJob $job): bool => $job->locale === 'tr' && $job->translationJobId !== null);

        $this->assertDatabaseHas('translation_jobs', [
            'job_type' => TranslationJob::TYPE_AUTO_REPLY,
            'subject_id' => $rule->id,
            'target_locale' => 'en',
            'status' => TranslationJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('translation_jobs', [
            'job_type' => TranslationJob::TYPE_AUTO_REPLY,
            'subject_id' => $rule->id,
            'target_locale' => 'tr',
            'status' => TranslationJob::STATUS_QUEUED,
        ]);
    }

    public function test_preview_selected_translation_renders_variables(): void
    {
        AutoReplyVariable::create([
            'key' => 'connector',
            'name' => 'Connector',
            'value' => 'https://t.me/relaxa_massage',
            'enabled' => true,
        ]);

        $rule = AutoReply::create(['trigger' => 'start', 'response' => 'Откройте {{connector}}']);
        AutoReplyTranslation::create([
            'auto_reply_id' => $rule->id,
            'locale' => 'en',
            'text' => 'Open {{connector}}',
            'status' => AutoReplyTranslation::STATUS_READY,
            'source' => AutoReplyTranslation::SOURCE_MANUAL,
        ]);

        Livewire::test(AutoReplyFormPage::class, ['rule' => $rule->id])
            ->set('selectedLocale', 'en')
            ->call('previewSelectedTranslation')
            ->assertSet('showTranslationPreview', true)
            ->assertSet('translationPreviewText', 'Open https://t.me/relaxa_massage');
    }

    public function test_translate_selected_language_queues_only_current_language(): void
    {
        Queue::fake();
        app(SettingsService::class)->set('support.languages', [
            'ru' => ['code' => 'ru', 'name' => 'Русский', 'native' => '🇷🇺 Русский', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 1],
            'en' => ['code' => 'en', 'name' => 'English', 'native' => '🇺🇸 English', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 2],
            'pl' => ['code' => 'pl', 'name' => 'Polski', 'native' => '🇵🇱 Polski', 'enabled' => true, 'show_on_start' => true, 'sort_order' => 3],
        ]);

        $rule = AutoReply::create(['trigger' => 'start', 'response' => 'Добрый день {{connector}}']);

        Livewire::test(AutoReplyFormPage::class, ['rule' => $rule->id])
            ->set('selectedLocale', 'en')
            ->call('translateSelectedLanguage')
            ->assertDispatched('admin-toast', message: 'Перевод текущего языка поставлен в очередь.', type: 'success');

        Queue::assertPushed(TranslateAutoReplyJob::class, 1);
        Queue::assertPushed(TranslateAutoReplyJob::class, fn (TranslateAutoReplyJob $job): bool => $job->locale === 'en');
        $this->assertDatabaseHas('translation_jobs', [
            'subject_id' => $rule->id,
            'target_locale' => 'en',
            'status' => TranslationJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseMissing('translation_jobs', [
            'subject_id' => $rule->id,
            'target_locale' => 'pl',
        ]);
    }
}
