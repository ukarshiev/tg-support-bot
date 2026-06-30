<?php

namespace App\Modules\PostEditBotBridge\Services;

use App\Models\BotUser;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PostEditBotBridgeClient
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('posteditbot_bridge.enabled', false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profileForBotUser(BotUser $botUser): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $apiUrl = trim((string) ($this->settings->get('posteditbot_bridge.api_url') ?? ''));
        $token = trim((string) ($this->settings->get('posteditbot_bridge.token') ?? ''));
        if ($apiUrl === '' || $token === '') {
            return ['found' => false, 'notes' => ['PostEditBot Bridge не настроен.']];
        }

        $ttl = (int) ($this->settings->get('posteditbot_bridge.cache_ttl_seconds') ?? 60);
        $cacheKey = 'posteditbot_bridge.profile.' . $botUser->id . '.' . md5($botUser->updated_at?->toISOString() ?? '');

        return Cache::remember($cacheKey, max(10, $ttl), function () use ($apiUrl, $token, $botUser): ?array {
            try {
                $timeoutSeconds = max(1, (int) (($this->settings->get('posteditbot_bridge.timeout_ms') ?? 5000) / 1000));
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->timeout($timeoutSeconds)
                    ->retry(1, 200)
                    ->get(rtrim($apiUrl, '/') . '/api/support/client-profile', [
                        'telegramId' => $botUser->platform === 'telegram' ? (string) $botUser->chat_id : null,
                        'telegramUsername' => $botUser->username,
                        'externalId' => $botUser->platform !== 'telegram' ? (string) $botUser->chat_id : null,
                        'source' => $botUser->platform,
                    ]);

                if (! $response->successful()) {
                    Log::channel('app')->warning('PostEditBot Bridge: API вернул ошибку', [
                        'status' => $response->status(),
                        'bot_user_id' => $botUser->id,
                    ]);

                    return ['found' => false, 'notes' => ['PostEditBot API временно недоступен.']];
                }

                $json = $response->json();
                return is_array($json) ? $json : null;
            } catch (\Throwable $e) {
                Log::channel('app')->warning('PostEditBot Bridge: ошибка запроса профиля', [
                    'bot_user_id' => $botUser->id,
                    'error' => $e->getMessage(),
                ]);

                return ['found' => false, 'notes' => ['Не удалось получить профиль из PostEditBot.']];
            }
        });
    }
}
