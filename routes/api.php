<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

// Auth Routes (V1)
Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });
});

Route::apiResource('products', ProductController::class);

Route::get('reports/summary', [SaleController::class, 'summary']);
Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);
