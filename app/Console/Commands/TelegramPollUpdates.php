<?php

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPollUpdates extends Command
{
    protected $signature = 'telegram:poll-updates {--once : Выполнить один цикл и завершиться} {--sleep=1 : Пауза между циклами без updates} {--timeout=25 : Таймаут long polling в Telegram}';

    protected $description = 'Poll Telegram updates and pass them to the existing Telegram webhook handler';

    public function handle(): int
    {
        $token = (string) app(SettingsService::class)->get('telegram.token');
        $secret = (string) app(SettingsService::class)->get('telegram.secret_key');

        if ($token === '' || $secret === '') {
            $this->error('telegram.token or telegram.secret_key is empty.');
            return Command::FAILURE;
        }

        $apiBase = "https://api.telegram.org/bot{$token}";
        $internalWebhookUrl = 'http://nginx/api/telegram/bot';
        $offset = null;
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $timeoutSeconds = max(1, min(50, (int) $this->option('timeout')));

        $deleteWebhook = Http::timeout(15)->post("{$apiBase}/deleteWebhook", [
            'drop_pending_updates' => false,
        ]);

        if (! $deleteWebhook->json('ok')) {
            $this->error('Failed to delete Telegram webhook before polling: ' . $deleteWebhook->body());
            return Command::FAILURE;
        }

        $this->info('Telegram polling started. Existing webhook is disabled.');

        do {
            $response = Http::timeout($timeoutSeconds + 10)->post("{$apiBase}/getUpdates", array_filter([
                'offset' => $offset,
                'timeout' => $timeoutSeconds,
                'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            ], static fn ($value) => $value !== null));

            if (! $response->json('ok')) {
                Log::channel('app')->error('Telegram poller: getUpdates failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                sleep($sleepSeconds);
                continue;
            }

            $updates = $response->json('result') ?? [];

            foreach ($updates as $update) {
                $updateId = (int) ($update['update_id'] ?? 0);
                $webhookResponse = Http::withHeaders([
                    'X-Telegram-Bot-Api-Secret-Token' => $secret,
                ])->timeout(30)->post($internalWebhookUrl, $update);

                if ($webhookResponse->status() >= 500) {
                    Log::channel('app')->error('Telegram poller: internal webhook failed', [
                        'status' => $webhookResponse->status(),
                        'body' => $webhookResponse->body(),
                        'update_id' => $updateId,
                    ]);
                    break;
                }

                if ($updateId > 0) {
                    $offset = $updateId + 1;
                }
            }

            if ($updates === []) {
                sleep($sleepSeconds);
            }
        } while (! $this->option('once'));

        return Command::SUCCESS;
    }
}
