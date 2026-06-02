<?php

use App\Modules\Telegram\Api\TelegramMethods;
use App\Modules\Telegram\Controllers\TelegramBotController;
use App\Modules\Telegram\Middleware\TelegramQuery;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => 'telegram',
], function () {
    Route::post('bot', [TelegramBotController::class, 'bot_query'])->middleware(TelegramQuery::class);

    Route::get('set_webhook', function () {
        $secretKey = (string) app(SettingsService::class)->get('telegram.secret_key');
        $queryParams = [
            'url' => config('app.url') . '/api/telegram/bot',
            'max_connections' => 40,
            'drop_pending_updates' => true,
            'secret_token' => $secretKey,
        ];
        $result = TelegramMethods::sendQueryTelegram('setWebhook', $queryParams);

        return response()->json($result->rawData);
    });
});
