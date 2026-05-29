<?php

namespace Tests\Unit\Livewire\Settings;

use App\Livewire\Settings\GeneralSettingsPage;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Unit-level tests for GeneralSettingsPage Livewire component.
 *
 * Focuses on business logic in isolation: mount(), save(), cancel()
 * methods using a mocked SettingsService so no DB or Livewire rendering
 * is required for the core logic assertions.
 */
class GeneralSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_mount_populates_properties_from_settings_service(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn('Test Bot');
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn('A description');
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn('admin_panel');

        $component = new GeneralSettingsPage();
        $component->mount($mock);

        $this->assertSame('Test Bot', $component->bot_name);
        $this->assertSame('A description', $component->bot_description);
        $this->assertSame('admin_panel', $component->manager_interface);
    }

    public function test_mount_uses_empty_string_when_settings_return_null(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn(null);
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn(null);
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn(null);

        $component = new GeneralSettingsPage();
        $component->mount($mock);

        $this->assertSame('', $component->bot_name);
        $this->assertSame('', $component->bot_description);
        $this->assertSame('telegram_group', $component->manager_interface);
    }

    public function test_save_calls_settings_service_set_for_all_three_keys(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        // mount call
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn('');
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn('');
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn('telegram_group');
        // save: reads current interface to detect change
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn('telegram_group');
        // save: writes
        $mock->shouldReceive('set')->with('app.bot_name', 'My Bot')->once();
        $mock->shouldReceive('set')->with('app.bot_description', 'Desc')->once();
        $mock->shouldReceive('set')->with('app.manager_interface', 'telegram_group')->once();

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->bot_name = 'My Bot';
        $component->bot_description = 'Desc';
        $component->manager_interface = 'telegram_group';
        $component->save($mock);

        $this->assertTrue($component->saved);
        $this->assertEmpty($component->formErrors);
    }

    public function test_save_sets_restart_notice_when_interface_changes(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn('');
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn('');
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn('telegram_group');
        $mock->shouldReceive('set')->with(Mockery::any(), Mockery::any());

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->manager_interface = 'admin_panel';
        $component->save($mock);

        $this->assertTrue($component->showRestartNotice);
    }

    public function test_save_does_not_persist_when_bot_name_exceeds_255(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn('');
        $mock->shouldNotReceive('set');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->bot_name = str_repeat('a', 256);
        $component->manager_interface = 'telegram_group';
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('bot_name', $component->formErrors);
    }

    public function test_save_does_not_persist_when_description_exceeds_1000(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn('');
        $mock->shouldNotReceive('set');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->bot_description = str_repeat('x', 1001);
        $component->manager_interface = 'telegram_group';
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('bot_description', $component->formErrors);
    }

    public function test_save_does_not_persist_when_manager_interface_invalid(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->andReturn('');
        $mock->shouldNotReceive('set');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        $component->manager_interface = 'not_valid';
        $component->save($mock);

        $this->assertFalse($component->saved);
        $this->assertArrayHasKey('manager_interface', $component->formErrors);
    }

    public function test_cancel_resets_properties_to_stored_values(): void
    {
        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn('Stored');
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn('Stored desc');
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn('admin_panel');

        $component = new GeneralSettingsPage();
        $component->mount($mock);
        // Simulate in-flight edits
        $component->bot_name = 'Changed';
        $component->saved = true;
        $component->showRestartNotice = true;

        $component->cancel($mock);

        $this->assertSame('Stored', $component->bot_name);
        $this->assertFalse($component->saved);
        $this->assertFalse($component->showRestartNotice);
        $this->assertEmpty($component->formErrors);
    }

    public function test_cache_flush_does_not_break_mount(): void
    {
        Cache::flush();
        config(['app.manager_interface' => 'telegram_group']);

        /** @var \Mockery\MockInterface&SettingsService $mock */
        $mock = Mockery::mock(SettingsService::class);
        $mock->shouldReceive('get')->with('app.bot_name')->andReturn(null);
        $mock->shouldReceive('get')->with('app.bot_description')->andReturn(null);
        $mock->shouldReceive('get')->with('app.manager_interface')->andReturn(null);

        $component = new GeneralSettingsPage();
        $component->mount($mock);

        $this->assertSame('telegram_group', $component->manager_interface);
    }
}
