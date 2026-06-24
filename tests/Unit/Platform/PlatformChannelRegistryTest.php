<?php

namespace Tests\Unit\Platform;

use App\Platform\PlatformChannelRegistry;
use Tests\Stubs\Platform\RecordingPlatformChannel;
use Tests\TestCase;

class PlatformChannelRegistryTest extends TestCase
{
    public function test_registers_and_resolves_channel_by_platform_key(): void
    {
        $registry = new PlatformChannelRegistry();
        $channel = new RecordingPlatformChannel('avito');

        $registry->register($channel);

        $this->assertTrue($registry->has('avito'));
        $this->assertSame($channel, $registry->for('avito'));
    }

    public function test_returns_null_and_false_for_unregistered_platform(): void
    {
        $registry = new PlatformChannelRegistry();

        $this->assertFalse($registry->has('avito'));
        $this->assertNull($registry->for('avito'));
    }

    public function test_register_overwrites_channel_with_same_platform_key(): void
    {
        $registry = new PlatformChannelRegistry();
        $first = new RecordingPlatformChannel('avito');
        $second = new RecordingPlatformChannel('avito');

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->for('avito'));
        $this->assertCount(1, $registry->platforms());
    }

    public function test_platforms_lists_registered_keys(): void
    {
        $registry = new PlatformChannelRegistry();
        $registry->register(new RecordingPlatformChannel('avito'));
        $registry->register(new RecordingPlatformChannel('whatsapp'));

        $this->assertEqualsCanonicalizing(['avito', 'whatsapp'], $registry->platforms());
    }

    public function test_registry_is_bound_as_singleton_in_container(): void
    {
        $this->assertSame(
            app(PlatformChannelRegistry::class),
            app(PlatformChannelRegistry::class),
        );
    }
}
