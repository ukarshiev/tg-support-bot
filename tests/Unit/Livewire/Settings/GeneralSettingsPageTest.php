<?php

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\GeneralSettingsPage;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level tests for GeneralSettingsPage Livewire component.
 *
 * The screen now manages a single setting — the Telegram topic-name template
 * (`telegram.template_topic_name`). Bot name, description, and manager
 * interface were removed from this screen.
 */
class GeneralSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_mount_populates_template_from_settings_service(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('telegram.template_topic_name')->andReturn('Обращение');

        $component = new GeneralSettingsPage();
        $component->mount($mock);

        $this->assertSame('Обращение', $component->template_topic_name);
    }

    public function test_mount_uses_empty_string_when_setting_returns_null(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('telegram.template_topic_name')->andReturn(null);

        $component = new GeneralSettingsPage();
        $component->mount($mock);

        $this->assertSame('', $component->template_topic_name);
    }

    public function test_save_persists_template_topic_name(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('telegram.template_topic_name')->andReturn('');
        $mock->shouldReceive('set')->with('telegram.template_topic_name', 'Новое обращение')->once();

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->template_topic_name = 'Новое обращение';
        $component->save($mock);

        $this->assertTrue($component->saved);
        $this->assertEmpty($component->formErrors);
    }

    public function test_save_does_not_persist_when_template_exceeds_255(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn('');
        $mock->shouldNotReceive('set');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->template_topic_name = str_repeat('a', 256);
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('template_topic_name', $component->formErrors);
    }

    public function test_cancel_resets_template_to_stored_value(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('telegram.template_topic_name')->andReturn('Stored');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->template_topic_name = 'Changed';
        $component->saved = true;

        $component->cancel($mock);

        $this->assertSame('Stored', $component->template_topic_name);
        $this->assertFalse($component->saved);
        $this->assertEmpty($component->formErrors);
    }
}
