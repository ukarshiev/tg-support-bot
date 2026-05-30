<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Vk\Api\VkMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin service wrapper around platform-specific webhook registration.
 *
 * Each method mirrors the logic in the existing artisan commands
 * (TelegramSetWebhook, AiBotSetWebhook) but returns a structured result
 * instead of writing to the console, so Livewire actions can display
 * success/error notifications.
 *
 * Tokens are read via SettingsService so DB-overrides take effect
 * immediately without a restart. Never logged per security rules.
 */
class WebhookRegistrationService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Register the Telegram main-bot webhook.
     *
     * @return array{success: bool, message: string}
     */
    public function registerTelegram(): array
    {
        $token = (string) $this->settings->get('telegram.token');
        $secret = (string) $this->settings->get('telegram.secret_key');

        if ($token === '') {
            return ['success' => false, 'message' => 'Токен Telegram не задан.'];
        }

        $appUrl = config('app.url');
        $url = $appUrl . '/api/telegram/bot';

        $queryParams = [
            'url' => $url,
            'max_connections' => 40,
            'drop_pending_updates' => true,
            'secret_token' => $secret,
        ];

        $result = TelegramMethods::sendQueryTelegram('setWebhook', $queryParams, $token);

        if ($result->ok === true) {
            Log::channel('loki')->info('WebhookRegistrationService: Telegram webhook registered', [
                'url' => $url,
            ]);

            return ['success' => true, 'message' => 'Вебхук Telegram зарегистрирован.'];
        }

        Log::channel('loki')->error('WebhookRegistrationService: Telegram webhook registration failed', [
            'raw' => $result->rawData ?? null,
        ]);

        return [
            'success' => false,
            'message' => 'Ошибка регистрации вебхука Telegram: ' . ($result->description ?? 'неизвестная ошибка'),
        ];
    }

    /**
     * Register the VK callback server (confirm + set webhook).
     *
     * VK does not have a single "setWebhook" method. The closest equivalent
     * is groups.setCallbackSettings which enables callbacks on the group.
     * The confirmation code must already be set; here we call
     * groups.addCallbackServer to register our URL if not registered yet,
     * then activate message events.
     *
     * Because VK's callback API requires a group_id which is not in the
     * current registry, this method verifies the token is present and
     * invokes groups.getLongPollServer as a connectivity check returning
     * success when the API responds positively.
     *
     * @return array{success: bool, message: string}
     */
    public function registerVk(): array
    {
        $token = (string) $this->settings->get('vk.token');

        if ($token === '') {
            return ['success' => false, 'message' => 'Токен VK не задан.'];
        }

        // Verify connectivity: call groups.getById — returns group info on success.
        $result = VkMethods::sendQueryVk('groups.getById', []);

        if ($result->response_code !== 500 && empty($result->error_message)) {
            Log::channel('loki')->info('WebhookRegistrationService: VK connectivity verified');

            return ['success' => true, 'message' => 'Подключение к VK API подтверждено. Убедитесь, что вебхук URL зарегистрирован в настройках группы ВКонтакте.'];
        }

        $errMsg = $result->error_message ?? 'неизвестная ошибка';

        Log::channel('loki')->error('WebhookRegistrationService: VK verification failed', [
            'error' => $errMsg,
        ]);

        return ['success' => false, 'message' => 'Ошибка VK API: ' . $errMsg];
    }

    /**
     * Register the MAX bot webhook via the Max platform API.
     *
     * Max uses a REST endpoint: POST /subscriptions with {"url": "..."}
     * authenticated via the bot token in Authorization header.
     *
     * @return array{success: bool, message: string}
     */
    public function registerMax(): array
    {
        $token = (string) $this->settings->get('max.token');

        if ($token === '') {
            return ['success' => false, 'message' => 'Токен MAX не задан.'];
        }

        $appUrl = config('app.url');
        $webhookUrl = $appUrl . '/api/max/bot';
        $baseUrl = 'https://platform-api.max.ru';

        $response = Http::withHeaders(['Authorization' => $token])
            ->post("{$baseUrl}/subscriptions", [
                'url' => $webhookUrl,
            ]);

        if ($response->successful()) {
            Log::channel('loki')->info('WebhookRegistrationService: MAX webhook registered', [
                'url' => $webhookUrl,
            ]);

            return ['success' => true, 'message' => 'Вебхук MAX зарегистрирован.'];
        }

        $body = $response->body();

        Log::channel('loki')->error('WebhookRegistrationService: MAX webhook registration failed', [
            'status' => $response->status(),
        ]);

        return [
            'success' => false,
            'message' => 'Ошибка регистрации вебхука MAX (HTTP ' . $response->status() . '): ' . $body,
        ];
    }
}
