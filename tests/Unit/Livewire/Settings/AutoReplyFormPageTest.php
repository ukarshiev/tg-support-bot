<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\AutoReplyFormPage;
use App\Models\AutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(1, AutoReply::query()->count());
    }

    public function test_save_requires_trigger_and_response(): void
    {
        Livewire::test(AutoReplyFormPage::class)
            ->set('trigger', '')
            ->set('response', '')
            ->call('save')
            ->assertHasErrors(['trigger', 'response']);

        $this->assertDatabaseCount('auto_replies', 0);
    }
}
