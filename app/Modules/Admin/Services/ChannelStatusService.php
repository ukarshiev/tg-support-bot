<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Services\Settings\SettingsService;

/**
 * Computes the connection status for each supported platform channel.
 *
 * A channel is considered "connected" when all required non-secret keys
 * are present AND at least one secret credential key is set.
 *
 * Required keys per platform:
 *   Telegram — telegram.token (secret), telegram.secret_key (secret), telegram.group_id
 *   VK       — vk.token (secret), vk.secret_key (secret), vk.confirm_code (secret)
 *   MAX      — max.token (secret), max.secret_key (secret)
 */
class ChannelStatusService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Return connection status for all three channels.
     *
     * @return array<string, array{connected: bool, label: string}>
     */
    public function all(): array
    {
        return [
            'telegram' => $this->telegram(),
            'vk' => $this->vk(),
            'max' => $this->max(),
        ];
    }

    /**
     * Telegram channel status.
     *
     * @return array{connected: bool, label: string}
     */
    public function telegram(): array
    {
        $connected = $this->isNonEmpty('telegram.token')
            && $this->isNonEmpty('telegram.secret_key')
            && $this->isNonEmpty('telegram.group_id');

        return [
            'connected' => $connected,
            'label' => $connected ? 'Подключён' : 'Не настроен',
        ];
    }

    /**
     * VK channel status.
     *
     * @return array{connected: bool, label: string}
     */
    public function vk(): array
    {
        $connected = $this->isNonEmpty('vk.token')
            && $this->isNonEmpty('vk.secret_key')
            && $this->isNonEmpty('vk.confirm_code');

        return [
            'connected' => $connected,
            'label' => $connected ? 'Подключён' : 'Не настроен',
        ];
    }

    /**
     * MAX channel status.
     *
     * @return array{connected: bool, label: string}
     */
    public function max(): array
    {
        $connected = $this->isNonEmpty('max.token')
            && $this->isNonEmpty('max.secret_key');

        return [
            'connected' => $connected,
            'label' => $connected ? 'Подключён' : 'Не настроен',
        ];
    }

    /**
     * Whether a settings key resolves to a non-empty value.
     */
    private function isNonEmpty(string $key): bool
    {
        $value = $this->settings->get($key);

        return $value !== null && $value !== '' && $value !== 0;
    }
}
