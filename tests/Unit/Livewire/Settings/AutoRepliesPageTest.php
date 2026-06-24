<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\AutoRepliesPage;
use App\Models\AutoReply;
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
            ->assertSee('2 правила');
    }

    public function test_shows_empty_state_when_no_rules(): void
    {
        Livewire::test(AutoRepliesPage::class)
            ->assertSee('Правил пока нет')
            ->assertSee('0 правил');
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
            ->assertDontSee('Привет');

        $this->assertDatabaseMissing('auto_replies', ['id' => $rule->id]);
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
}
