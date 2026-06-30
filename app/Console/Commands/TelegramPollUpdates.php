<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\Controllers\TelegramBotController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramPollUpdates extends Command
{
    protected $signature = 'telegram:poll-updates {--once : Выполнить один цикл polling и завершиться}';

    protected $description = 'Poll Telegram updates when public webhook delivery is unavailable';

    private const OFFSET_CACHE_KEY = 'telegram.polling.update_offset';

    public function handle(): int
    {
        $this->disableWebhookForPolling();

        do {
            $this->pollOnce();
        } while (!$this->option('once'));

        return self::SUCCESS;
    }

    private function disableWebhookForPolling(): void
    {
        $response = TelegramMethods::sendQueryTelegram('deleteWebhook', [
            'drop_pending_updates' => false,
        ]);

        if (!$response->ok) {
            Log::channel('app')->warning('TelegramPollUpdates: deleteWebhook failed', [
                'raw' => $response->rawData,
            ]);
        }
    }

    private function pollOnce(): void
    {
        $offset = Cache::get(self::OFFSET_CACHE_KEY);

        $response = TelegramMethods::sendQueryTelegram('getUpdates', [
            'offset' => $offset,
            'timeout' => 25,
            'limit' => 50,
            'allowed_updates' => json_encode([
                'message',
                'edited_message',
                'callback_query',
            ]),
        ]);

        if (!$response->ok) {
            Log::channel('app')->warning('TelegramPollUpdates: getUpdates failed', [
                'raw' => $response->rawData,
            ]);

            sleep(3);
            return;
        }

        $updates = $response->rawData['result'] ?? [];
        if (!is_array($updates)) {
            return;
        }

        foreach ($updates as $update) {
            if (!is_array($update) || !isset($update['update_id'])) {
                continue;
            }

            $this->handleUpdate($update);
            Cache::forever(self::OFFSET_CACHE_KEY, ((int) $update['update_id']) + 1);
        }
    }

    /**
     * @param array<string, mixed> $update
     */
    private function handleUpdate(array $update): void
    {
        try {
            Log::channel('app')->info(json_encode($update), ['source' => 'tg_polling_update']);

            $request = Request::create('/api/telegram/bot', 'POST', $update);
            $controller = new TelegramBotController($request);
            $controller->bot_query();
        } catch (\Throwable $e) {
            Log::channel('app')->error('TelegramPollUpdates: update handling failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
