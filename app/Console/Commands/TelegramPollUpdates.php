<?php

namespace App\Console\Commands;

use App\Enums\TelegramPollerApiResult;
use App\Services\Settings\SettingsService;
use App\Support\TelegramPollingRuntime;
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
        $runtime = app(TelegramPollingRuntime::class);
        $runtime->resetHeartbeat('main');

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

        while (($preflight = $runtime->preflight('main', 'Telegram poller', $token)) !== TelegramPollerApiResult::Success) {
            if ($this->option('once')) {
                return Command::FAILURE;
            }

            sleep($runtime->retryDelay($preflight, $sleepSeconds));
        }

        while (($deleteWebhook = $this->deleteWebhook($apiBase, $runtime)) !== TelegramPollerApiResult::Success) {
            if ($this->option('once')) {
                return Command::FAILURE;
            }

            sleep($runtime->retryDelay($deleteWebhook, $sleepSeconds));
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
                $runtime->reportTransportFailure('main', 'Telegram poller', 'get_updates', $e);
                sleep($sleepSeconds);
                continue;
            }

            if (! $response->json('ok')) {
                $body = $response->body();
                $failure = $runtime->classifyResponse('main', 'Telegram poller', 'get_updates', $response);

                if (str_contains($body, "can't use getUpdates method while webhook is active")) {
                    $failure = $this->deleteWebhook($apiBase, $runtime);
                }

                sleep($runtime->retryDelay($failure, $sleepSeconds));
                continue;
            }

            $runtime->beat('main');
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
                    $runtime->reportTransportFailure('main', 'Telegram poller', 'internal_webhook', $e, [
                        'update_id' => $updateId,
                    ]);
                    sleep($sleepSeconds);
                    continue 2;
                }

                if (! $webhookResponse->successful()) {
                    Log::channel('app')->error('Telegram poller: internal webhook rejected update; offset is not advanced', [
                        'source' => 'telegram_poller_internal_webhook_rejected',
                        'status' => $webhookResponse->status(),
                        'body' => $runtime->sanitize($webhookResponse->body()),
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

    private function deleteWebhook(string $apiBase, TelegramPollingRuntime $runtime): TelegramPollerApiResult
    {
        try {
            $deleteWebhook = Http::connectTimeout(2)
                ->timeout(10)
                ->when(config('traffic_source.telegram.force_ipv4'), fn ($client) => $client->withOptions(['force_ip_resolve' => 'v4']))
                ->post("{$apiBase}/deleteWebhook", [
                    'drop_pending_updates' => false,
                ]);
        } catch (\Throwable $e) {
            return $runtime->reportTransportFailure('main', 'Telegram poller', 'delete_webhook', $e);
        }

        if (! $deleteWebhook->json('ok')) {
            return $runtime->classifyResponse('main', 'Telegram poller', 'delete_webhook', $deleteWebhook);
        }

        $this->info('Telegram polling started. Existing webhook is disabled.');

        return TelegramPollerApiResult::Success;
    }
}
