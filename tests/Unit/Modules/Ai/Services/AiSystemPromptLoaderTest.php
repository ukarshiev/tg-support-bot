<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai\Services;

use App\Modules\Ai\Services\AiSystemPromptLoader;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSystemPromptLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_returns_empty_string_when_no_db_value(): void
    {
        $this->assertSame('', (new AiSystemPromptLoader())->render());
    }

    public function test_render_returns_db_value_when_set(): void
    {
        app(SettingsService::class)->set(AiSystemPromptLoader::SETTING_KEY, 'Промпт из БД');

        $this->assertSame('Промпт из БД', (new AiSystemPromptLoader())->render());
    }

    public function test_render_memoizes_for_the_object_lifetime(): void
    {
        $loader = new AiSystemPromptLoader();
        app(SettingsService::class)->set(AiSystemPromptLoader::SETTING_KEY, 'Первый');

        $first = $loader->render();
        app(SettingsService::class)->set(AiSystemPromptLoader::SETTING_KEY, 'Второй');

        $this->assertSame('Первый', $first);
        $this->assertSame('Первый', $loader->render());
    }
}
