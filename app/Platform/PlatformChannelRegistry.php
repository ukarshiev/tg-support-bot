<?php

namespace App\Platform;

use App\Contracts\PlatformChannel;

/**
 * Registry of pluggable platform channels.
 *
 * Bound as a singleton in the container ({@see \App\Providers\AppServiceProvider}).
 * Pluggable platform modules register their {@see PlatformChannel} from their
 * ServiceProvider::boot(), and the core resolves the channel by platform key for
 * cross-platform delivery. If no channel is registered for a platform, the core
 * keeps its previous behavior (logs "unsupported platform").
 */
class PlatformChannelRegistry
{
    /**
     * Registered channels, keyed by PlatformChannel::platform().
     *
     * @var array<string, PlatformChannel>
     */
    private array $channels = [];

    /**
     * Register a platform channel. Re-registering the same key overwrites the
     * previous channel.
     *
     * @param PlatformChannel $channel
     *
     * @return void
     */
    public function register(PlatformChannel $channel): void
    {
        $this->channels[$channel->platform()] = $channel;
    }

    /**
     * Return the channel for a platform, or null if none is registered.
     *
     * @param string $platform
     *
     * @return PlatformChannel|null
     */
    public function for(string $platform): ?PlatformChannel
    {
        return $this->channels[$platform] ?? null;
    }

    /**
     * Whether a channel is registered for the platform.
     *
     * @param string $platform
     *
     * @return bool
     */
    public function has(string $platform): bool
    {
        return isset($this->channels[$platform]);
    }

    /**
     * Keys of the registered platforms.
     *
     * @return array<int, string>
     */
    public function platforms(): array
    {
        return array_keys($this->channels);
    }
}
