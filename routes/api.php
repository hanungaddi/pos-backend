<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

// Auth Routes (V1)
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockReceivingController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockOpnameController;

// API Routes V1
Route::prefix('v1')->group(function () {
    // Auth Routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
        
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // User Management CRUD Routes
    Route::middleware(['auth:sanctum', 'permission:manage_users'])->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // Inventory Management Routes
    Route::middleware(['auth:sanctum'])->prefix('inventory')->group(function () {
        // Movements (Supervisor+)
        Route::middleware(['permission:view_inventory'])->group(function () {
            Route::get('movements', [StockMovementController::class, 'index']);
            Route::get('movements/{productId}', [StockMovementController::class, 'showByProduct']);
        });

        // Operations (Manager+)
        Route::middleware(['permission:manage_inventory'])->group(function () {
            Route::get('receiving', [StockReceivingController::class, 'index']);
            Route::post('receiving', [StockReceivingController::class, 'store']);
            Route::get('receiving/{id}', [StockReceivingController::class, 'show']);

            Route::post('adjustment', [StockAdjustmentController::class, 'store']);

            Route::get('opname', [StockOpnameController::class, 'index']);
            Route::post('opname', [StockOpnameController::class, 'store']);
            Route::get('opname/{id}', [StockOpnameController::class, 'show']);
            Route::put('opname/{id}', [StockOpnameController::class, 'update']);
        });
    });
});

Route::apiResource('products', ProductController::class);

Route::get('reports/summary', [SaleController::class, 'summary']);
Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);
