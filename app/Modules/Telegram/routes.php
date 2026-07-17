<?php

use App\Modules\Telegram\Controllers\TelegramBotController;
use App\Modules\Telegram\Middleware\TelegramQuery;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => 'telegram',
], function () {
    Route::post('bot', [TelegramBotController::class, 'bot_query'])->middleware(TelegramQuery::class);
});
