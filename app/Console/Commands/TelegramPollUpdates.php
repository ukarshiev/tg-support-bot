<?php

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPollUpdates extends Command
{
    protected $signature = 'telegram:poll-updates {--once : Выполнить один цикл и завершиться} {--sleep=1 : Пауза между циклами без updates} {--timeout=10 : Таймаут long polling в Telegram}';

    protected $description = 'Poll Telegram updates and pass them to the existing Telegram webhook handler';

    public function handle(): int
    {
        if (config('traffic_source.telegram.ingress_mode', 'polling') !== 'polling') {
            $this->info('Telegram poller disabled: TELEGRAM_INGRESS_MODE is not polling.');
            return Command::SUCCESS;
        }

        $token = (string) app(SettingsService::class)->get('telegram.token');
        $secret = (string) app(SettingsService::class)->get('telegram.secret_key');

        if ($token === '' || $secret === '') {
            $this->error('telegram.token or telegram.secret_key is empty.');
            return Command::FAILURE;
        }

        $apiBase = "https://api.telegram.org/bot{$token}";
        $internalWebhookUrl = 'http://nginx/api/telegram/bot';
        $offset = Cache::get('telegram:poller:offset');
        $offset = is_numeric($offset) ? (int) $offset : null;
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $timeoutSeconds = max(1, min(50, (int) $this->option('timeout')));

        while (! $this->deleteWebhook($apiBase, $sleepSeconds)) {
            if ($this->option('once')) {
                return Command::FAILURE;
            }
        }

        do {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout($timeoutSeconds + 3)
                    ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                    ->post("{$apiBase}/getUpdates", array_filter([
                        'offset' => $offset,
                        'timeout' => $timeoutSeconds,
                        'allowed_updates' => ['message', 'edited_message', 'callback_query'],
                    ], static fn ($value) => $value !== null));
            } catch (\Throwable $e) {
                Log::channel('app')->warning('Telegram poller: getUpdates transport failed, retrying', [
                    'source' => 'telegram_poller_get_updates_transport_failed',
                    'error' => $this->sanitizeTelegramError($e->getMessage()),
                ]);
                sleep($sleepSeconds);
                continue;
            }

            if (! $response->json('ok')) {
                $body = $response->body();
                Log::channel('app')->error('Telegram poller: getUpdates failed', [
                    'source' => 'telegram_poller_get_updates_failed',
                    'status' => $response->status(),
                    'body' => $this->sanitizeTelegramError($body),
                ]);

                if (str_contains($body, "can't use getUpdates method while webhook is active")) {
                    $this->deleteWebhook($apiBase, $sleepSeconds);
                }

                sleep($sleepSeconds);
                continue;
            }

            $updates = $response->json('result') ?? [];

            foreach ($updates as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                $traceId = !empty($update['callback_query']['id'])
                    ? 'telegram:callback:' . $update['callback_query']['id']
                    : 'telegram:update:' . $updateId;
                Log::channel('app')->info('Telegram pipeline', [
                    'source' => 'telegram_pipeline',
                    'pipeline_event' => 'telegram_update_received',
                    'trace_id' => $traceId,
                    'update_id' => $updateId,
                    'at_unix_ms' => (int) floor(microtime(true) * 1000),
                ]);
                try {
                    $webhookResponse = Http::withHeaders([
                        'X-Telegram-Bot-Api-Secret-Token' => $secret,
                    ])->connectTimeout(2)->timeout(8)->post($internalWebhookUrl, $update);
                } catch (\Throwable $e) {
                    Log::channel('app')->warning('Telegram poller: internal webhook transport failed, retrying update later', [
                        'source' => 'telegram_poller_internal_webhook_transport_failed',
                        'update_id' => $updateId,
                        'error' => $this->sanitizeTelegramError($e->getMessage()),
                    ]);
                    sleep($sleepSeconds);
                    continue 2;
                }

                if (! $webhookResponse->successful()) {
                    Log::channel('app')->error('Telegram poller: internal webhook rejected update; offset is not advanced', [
                        'source' => 'telegram_poller_internal_webhook_rejected',
                        'status' => $webhookResponse->status(),
                        'body' => $this->sanitizeTelegramError($webhookResponse->body()),
                        'update_id' => $updateId,
                    ]);
                    sleep($sleepSeconds);
                    continue 2;
                }

                if ($updateId > 0) {
                    $offset = $updateId + 1;
                    Cache::forever('telegram:poller:offset', $offset);
                }
            }
        } while (! $this->option('once'));

        return Command::SUCCESS;
    }

    private function deleteWebhook(string $apiBase, int $sleepSeconds): bool
    {
        try {
            $deleteWebhook = Http::connectTimeout(2)
                ->timeout(10)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->post("{$apiBase}/deleteWebhook", [
                    'drop_pending_updates' => false,
                ]);
        } catch (\Throwable $e) {
            Log::channel('app')->warning('Telegram poller: deleteWebhook transport failed, retrying', [
                'source' => 'telegram_poller_delete_webhook_transport_failed',
                'error' => $this->sanitizeTelegramError($e->getMessage()),
            ]);
            sleep($sleepSeconds);
            return false;
        }

        if (! $deleteWebhook->json('ok')) {
            Log::channel('app')->warning('Telegram poller: deleteWebhook returned non-ok, retrying', [
                'source' => 'telegram_poller_delete_webhook_non_ok',
                'status' => $deleteWebhook->status(),
                'body' => $this->sanitizeTelegramError($deleteWebhook->body()),
            ]);
            sleep($sleepSeconds);
            return false;
        }

        $this->info('Telegram polling started. Existing webhook is disabled.');

        return true;
    }

    private function sanitizeTelegramError(string $message): string
    {
        return preg_replace('/bot[0-9]+:[A-Za-z0-9_-]+/', 'bot[hidden]', $message) ?? $message;
    }
}
