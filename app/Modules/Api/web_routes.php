<?php

use App\Modules\Api\Controllers\SimplePage;
use App\Modules\Api\Controllers\SwaggerController;
use Illuminate\Support\Facades\Route;

Route::prefix('docs')->group(function () {
    Route::get('/swagger-v1-json', [SwaggerController::class, 'showSwagger']);
    Route::get('/swagger-v1-ui', [SwaggerController::class, 'swaggerUi']);
});

Route::get('/', [SimplePage::class, 'index']);
