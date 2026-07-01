<?php

namespace App\Console\Commands;

use App\Modules\Telegram\Api\TelegramMethods;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Set Telegram Webhook for bot';

    /**
     * @return int
     */
    public function handle(): int
    {
        $appUrl = config('app.url');
        $url = $appUrl . '/api/telegram/bot';
        $secret = (string) app(SettingsService::class)->get('telegram.secret_key');

        $queryParams = [
            'url' => $url,
            'max_connections' => 5,
            'drop_pending_updates' => true,
            'secret_token' => $secret,
        ];

        $result = TelegramMethods::sendQueryTelegram('setWebhook', $queryParams);

        if (isset($result->rawData)) {
            $this->info('Webhook set:');
            $this->line(json_encode($result->rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error('Error setting webhook');
        }

        return Command::SUCCESS;
    }
}
