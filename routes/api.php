<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

// Auth Routes (V1)
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockReceivingController;
use App\Http\Controllers\Api\V1\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockOpnameController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\CashDrawerController;

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
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
    });

    // Product V1 Routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Authenticated Read for All (Kasir, Supervisor, Manajer, Admin)
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/barcode/{barcode}', [ProductController::class, 'showByBarcode']);
        Route::get('products/{product}', [ProductController::class, 'show']);

        // Manage (Supervisor, Manajer, Admin)
        Route::middleware(['permission:manage_products'])->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
            Route::patch('products/{product}/status', [ProductController::class, 'changeStatus']);
        });
    });

    // Transaction & Report Management Routes (V1)
    Route::middleware(['auth:sanctum'])->group(function () {
        // Cash Drawer Operations
        Route::prefix('cash-drawer')->group(function () {
            Route::middleware(['permission:view_cash_drawer'])->group(function () {
                Route::get('sessions', [CashDrawerController::class, 'index']);
            });

            Route::middleware(['permission:operate_cash_drawer|manage_cash_drawer'])->group(function () {
                Route::get('current', [CashDrawerController::class, 'current']);
                Route::post('open', [CashDrawerController::class, 'open']);
                Route::post('sessions/{session}/cash-in', [CashDrawerController::class, 'cashIn']);
                Route::post('sessions/{session}/cash-out', [CashDrawerController::class, 'cashOut']);
                Route::post('sessions/{session}/close', [CashDrawerController::class, 'close']);
            });

            Route::middleware(['permission:operate_cash_drawer|view_cash_drawer'])->group(function () {
                Route::get('sessions/{session}', [CashDrawerController::class, 'show']);
            });
        });

        // Cashier+ Operations (create_sales)
        Route::middleware(['permission:create_sales'])->group(function () {
            Route::get('transactions', [TransactionController::class, 'index']); // Paginated history
            Route::get('transactions/on-hold', [TransactionController::class, 'listOnHold']);
            Route::post('transactions', [TransactionController::class, 'store']);
            Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
            Route::post('transactions/{transaction}/items', [TransactionController::class, 'addItem']);
            Route::put('transactions/{transaction}/items/{itemId}', [TransactionController::class, 'updateItem']);
            Route::delete('transactions/{transaction}/items/{itemId}', [TransactionController::class, 'removeItem']);
            Route::post('transactions/{transaction}/hold', [TransactionController::class, 'hold']);
            Route::post('transactions/{transaction}/recall', [TransactionController::class, 'recall']);
            Route::post('transactions/{transaction}/pay/cash', [TransactionController::class, 'payCash']);
            Route::post('transactions/{transaction}/pay/card', [TransactionController::class, 'payCard']);
            Route::post('transactions/{transaction}/pay/split', [TransactionController::class, 'paySplit']);
        });

        // Supervisor+ Operations (manage_sales)
        Route::middleware(['permission:manage_sales'])->group(function () {
            Route::post('transactions/{transaction}/void', [TransactionController::class, 'void']);
        });

        // Reports (view_reports)
        Route::middleware(['permission:view_reports'])->prefix('reports')->group(function () {
            Route::get('summary', [ReportController::class, 'summary']);
            Route::get('sales/daily', [ReportController::class, 'daily']);
        });
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
            Route::put('receiving/{id}', [StockReceivingController::class, 'update']);
            Route::delete('receiving/{id}', [StockReceivingController::class, 'destroy']);
            Route::patch('receiving/{id}/payment-status', [StockReceivingController::class, 'updatePaymentStatus']);

            Route::post('adjustment', [StockAdjustmentController::class, 'store']);

            Route::get('opname', [StockOpnameController::class, 'index']);
            Route::post('opname', [StockOpnameController::class, 'store']);
            Route::get('opname/{id}', [StockOpnameController::class, 'show']);
            Route::put('opname/{id}', [StockOpnameController::class, 'update']);
            Route::delete('opname/{id}', [StockOpnameController::class, 'destroy']);

            Route::get('suppliers/all', [SupplierController::class, 'all']);
            Route::apiResource('suppliers', SupplierController::class);
        });
    });
});
