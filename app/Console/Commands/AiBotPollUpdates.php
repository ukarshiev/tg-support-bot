<?php

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiBotPollUpdates extends Command
{
    protected $signature = 'ai-bot:poll-updates {--once : Выполнить один цикл и завершиться} {--sleep=1 : Пауза между циклами без updates} {--timeout=25 : Таймаут long polling в Telegram}';

    protected $description = 'Poll Telegram AI bot callback updates and pass them to the internal AI webhook handler';

    public function handle(): int
    {
        $settings = app(SettingsService::class);
        $token = (string) $settings->get('telegram_ai.token');
        $secret = (string) $settings->get('telegram_ai.secret');

        if ($token === '' || $secret === '') {
            $this->error('telegram_ai.token or telegram_ai.secret is empty.');

            return Command::FAILURE;
        }

        $apiBase = "https://api.telegram.org/bot{$token}";
        $internalWebhookUrl = 'http://nginx/api/ai-bot/webhook';
        $offset = Cache::get('telegram:ai-poller:offset');
        $offset = is_numeric($offset) ? (int) $offset : null;
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $timeoutSeconds = max(1, min(50, (int) $this->option('timeout')));

        while (! $this->deleteWebhook($apiBase, $sleepSeconds)) {
            if ($this->option('once')) {
                return Command::FAILURE;
            }
        }

        $this->info('AI bot polling started. Existing AI bot webhook is disabled.');

        do {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout($timeoutSeconds + 3)
                    ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                    ->post("{$apiBase}/getUpdates", array_filter([
                        'offset' => $offset,
                        'timeout' => $timeoutSeconds,
                        'allowed_updates' => ['callback_query'],
                    ], static fn ($value) => $value !== null));
            } catch (\Throwable $e) {
                Log::channel('app')->warning('AI bot poller: getUpdates transport failed; offset is preserved', [
                    'source' => 'ai_bot_poller_get_updates_transport_failed',
                    'error' => $this->sanitizeTelegramError($e->getMessage()),
                ]);
                sleep($sleepSeconds);
                continue;
            }

            if (! $response->json('ok')) {
                Log::channel('app')->error('AI bot poller: getUpdates failed', [
                    'status' => $response->status(),
                    'body' => $this->sanitizeTelegramError($response->body()),
                ]);
                sleep($sleepSeconds);
                continue;
            }

            $updates = $response->json('result') ?? [];

            foreach ($updates as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                try {
                    $webhookResponse = Http::withHeaders([
                        'X-Telegram-Bot-Api-Secret-Token' => $secret,
                    ])->connectTimeout(2)->timeout(8)->post($internalWebhookUrl, $update);
                } catch (\Throwable $e) {
                    Log::channel('app')->warning('AI bot poller: internal webhook transport failed; offset is preserved', [
                        'source' => 'ai_bot_poller_internal_webhook_transport_failed',
                        'update_id' => $updateId,
                        'error' => $this->sanitizeTelegramError($e->getMessage()),
                    ]);
                    sleep($sleepSeconds);
                    continue 2;
                }

                if (! $webhookResponse->successful()) {
                    Log::channel('app')->error('AI bot poller: internal webhook rejected update; offset is not advanced', [
                        'source' => 'ai_bot_poller_rejected',
                        'status' => $webhookResponse->status(),
                        'body' => $this->sanitizeTelegramError($webhookResponse->body()),
                        'update_id' => $updateId,
                    ]);
                    sleep($sleepSeconds);
                    continue 2;
                }

                Log::channel('app')->info('AI bot poller: callback forwarded', [
                    'source' => 'ai_bot_poller_forwarded',
                    'update_id' => $updateId,
                ]);

                if ($updateId > 0) {
                    $offset = $updateId + 1;
                    Cache::forever('telegram:ai-poller:offset', $offset);
                }
            }

            if ($updates === []) {
                sleep($sleepSeconds);
            }
        } while (! $this->option('once'));

        return Command::SUCCESS;
    }

    private function deleteWebhook(string $apiBase, int $sleepSeconds): bool
    {
        try {
            $response = Http::connectTimeout(2)
                ->timeout(10)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->post("{$apiBase}/deleteWebhook", ['drop_pending_updates' => false]);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('AI bot poller: deleteWebhook transport failed, retrying', [
                'source' => 'ai_bot_poller_delete_webhook_transport_failed',
                'error' => $this->sanitizeTelegramError($e->getMessage()),
            ]);
            sleep($sleepSeconds);

            return false;
        }

        if (! $response->json('ok')) {
            Log::channel('app')->warning('AI bot poller: deleteWebhook returned non-ok, retrying', [
                'source' => 'ai_bot_poller_delete_webhook_non_ok',
                'status' => $response->status(),
                'body' => $this->sanitizeTelegramError($response->body()),
            ]);
            sleep($sleepSeconds);

            return false;
        }

        return true;
    }

    private function sanitizeTelegramError(string $message): string
    {
        return preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/', 'bot[hidden]', $message) ?? $message;
    }
}
