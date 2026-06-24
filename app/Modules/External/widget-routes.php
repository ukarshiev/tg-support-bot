<?php

use App\Modules\External\Controllers\WidgetController;
use App\Modules\External\Middleware\WidgetGate;
use Illuminate\Support\Facades\Route;

Route::middleware([WidgetGate::class])
    ->prefix('widget/{external_id}')
    ->group(function () {
        Route::options('{any}', [WidgetController::class, 'preflight'])->where('any', '.*');
        Route::post('messages', [WidgetController::class, 'sendMessage']);
        Route::post('files', [WidgetController::class, 'sendFile']);
        Route::get('messages', [WidgetController::class, 'getMessages']);
    });
