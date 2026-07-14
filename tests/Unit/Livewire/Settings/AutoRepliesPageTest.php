<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\AutoRepliesPage;
use App\Models\AutoReply;
use App\Models\AutoReplyVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutoRepliesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_the_page_title_and_subtitle(): void
    {
        Livewire::test(AutoRepliesPage::class)
            ->assertOk()
            ->assertSee('Автоответы')
            ->assertSee('Настройте автоматические ответы на частые вопросы');
    }

    public function test_lists_rules_from_the_database(): void
    {
        AutoReply::create(['trigger' => 'Привет', 'response' => 'Здравствуйте!', 'enabled' => true]);
        AutoReply::create(['trigger' => 'Цена', 'response' => 'Тарифы', 'enabled' => false]);

        Livewire::test(AutoRepliesPage::class)
            ->assertSee('Привет')
            ->assertSee('Цена')
            ->assertSee('7 правил')
            ->assertSee('Приветственное сообщение');
    }

    public function test_shows_system_welcome_from_language_migration(): void
    {
        Livewire::test(AutoRepliesPage::class)
            ->assertSee('__system_welcome__')
            ->assertSee('Приветственное сообщение')
            ->assertSee('5 правил');
    }

    public function test_does_not_render_status_column(): void
    {
        AutoReply::create(['trigger' => 'Привет', 'response' => 'Здравствуйте!', 'enabled' => true]);

        Livewire::test(AutoRepliesPage::class)
            ->assertSee('Триггер')
            ->assertSee('Ответ')
            ->assertSee('Действия')
            ->assertDontSee('Статус');
    }

    public function test_delete_rule_removes_it_from_the_database(): void
    {
        $rule = AutoReply::create(['trigger' => 'Привет', 'response' => 'Здравствуйте!', 'enabled' => true]);

        Livewire::test(AutoRepliesPage::class)
            ->assertSee('Привет')
            ->call('deleteRule', $rule->id)
            ->assertDontSee('>Привет<', false);

        $this->assertDatabaseMissing('auto_replies', ['id' => $rule->id]);
    }

    public function test_system_rule_cannot_be_deleted(): void
    {
        $rule = AutoReply::query()->where('type', AutoReply::TYPE_DIALOG_CLOSED)->firstOrFail();

        Livewire::test(AutoRepliesPage::class)
            ->call('deleteRule', $rule->id)
            ->assertDispatched('admin-toast', message: 'Системный автоответ нельзя удалить. Его можно отключить.', type: 'danger');

        $this->assertDatabaseHas('auto_replies', ['id' => $rule->id]);
    }

    #[DataProvider('rulesCountProvider')]
    public function test_rules_count_label_uses_russian_plural(int $count, string $expected): void
    {
        $component = Livewire::test(AutoRepliesPage::class);

        $this->assertSame($expected, $component->instance()->rulesCountLabel($count));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rulesCountProvider(): array
    {
        return [
            'one' => [1, '1 правило'],
            'two' => [2, '2 правила'],
            'four' => [4, '4 правила'],
            'five' => [5, '5 правил'],
            'eleven' => [11, '11 правил'],
            'twenty-one' => [21, '21 правило'],
        ];
    }

    public function test_variables_tab_creates_and_edits_variable(): void
    {
        Livewire::test(AutoRepliesPage::class)
            ->call('setTab', 'variables')
            ->set('variableKey', 'Connector Link')
            ->set('variableName', 'Ссылка Connector')
            ->set('variableValue', 'https://t.me/relaxa_massage')
            ->set('variableDescription', 'Канал Connector')
            ->call('saveVariable')
            ->assertHasNoErrors()
            ->assertDispatched('admin-toast', message: 'Переменная сохранена.', type: 'success');

        $this->assertDatabaseHas('auto_reply_variables', [
            'key' => 'connector_link',
            'name' => 'Ссылка Connector',
            'value' => 'https://t.me/relaxa_massage',
            'enabled' => true,
        ]);

        $variable = AutoReplyVariable::where('key', 'connector_link')->firstOrFail();

        Livewire::test(AutoRepliesPage::class)
            ->call('editVariable', $variable->id)
            ->assertSet('variableKey', 'connector_link')
            ->set('variableName', 'Connector')
            ->call('saveVariable')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('auto_reply_variables', [
            'id' => $variable->id,
            'name' => 'Connector',
        ]);
    }

    public function test_used_variable_is_disabled_instead_of_deleted(): void
    {
        $variable = AutoReplyVariable::create([
            'key' => 'connector',
            'name' => 'Connector',
            'value' => 'https://t.me/relaxa_massage',
            'enabled' => true,
        ]);
        AutoReply::create(['trigger' => 'start', 'response' => 'Open {{connector}}']);

        Livewire::test(AutoRepliesPage::class)
            ->call('deleteVariable', $variable->id)
            ->assertDispatched('admin-toast', message: 'Переменная используется, поэтому она выключена, а не удалена.', type: 'danger');

        $this->assertDatabaseHas('auto_reply_variables', [
            'id' => $variable->id,
            'enabled' => false,
        ]);
    }
}
