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
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\RolePermissionController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReceivingPaymentController;
use App\Http\Controllers\Api\V1\PurchaseReturnController;

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

    // User Management & Activity Logs
    Route::middleware(['auth:sanctum'])->group(function () {
        // Read Users
        Route::middleware(['permission:view_users|manage_users'])->group(function () {
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/{user}', [UserController::class, 'show']);
        });

        // Write/Modify Users
        Route::middleware(['permission:manage_users'])->group(function () {
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });

        // Activity Logs
        Route::middleware(['permission:view_audit_logs'])->group(function () {
            Route::get('activity-logs', [ActivityLogController::class, 'index']);
        });
    });

    // Role & Permission Management (Only Admin)
    Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('roles', [RolePermissionController::class, 'indexRoles']);
        Route::get('permissions', [RolePermissionController::class, 'indexPermissions']);
        Route::post('roles/{role}/permissions', [RolePermissionController::class, 'assignPermission']);
        Route::delete('roles/{role}/permissions/{permission}', [RolePermissionController::class, 'revokePermission']);
    });

    // Product, Category, and Brand V1 Routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Read Products
        Route::middleware(['permission:view_products|manage_products'])->group(function () {
            Route::get('products', [ProductController::class, 'index']);
            Route::get('products/barcode/{barcode}', [ProductController::class, 'showByBarcode']);
            Route::get('products/price-logs', [ProductController::class, 'allPriceLogs']);
            Route::get('products/{id}/price-logs', [ProductController::class, 'itemPriceLogs']);
            Route::get('products/{product}', [ProductController::class, 'show']);
            Route::get('products/{id}/print-barcode', [ProductController::class, 'printBarcode']);
            Route::match(['get', 'post'], 'products/print-barcodes', [ProductController::class, 'printBarcodesBulk']);

            Route::get('categories', [CategoryController::class, 'index']);
            Route::get('categories/{category}', [CategoryController::class, 'show']);

            Route::get('brands', [BrandController::class, 'index']);
            Route::get('brands/{brand}', [BrandController::class, 'show']); 
        });

        // Manage Products
        Route::middleware(['permission:manage_products'])->group(function () {
            Route::post('products', [ProductController::class, 'store']);
            Route::put('products/{product}', [ProductController::class, 'update']);
            Route::delete('products/{product}', [ProductController::class, 'destroy']);
            Route::patch('products/{product}/status', [ProductController::class, 'changeStatus']);

            Route::post('categories', [CategoryController::class, 'store']);
            Route::put('categories/{category}', [CategoryController::class, 'update']);
            Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

            Route::post('brands', [BrandController::class, 'store']);
            Route::put('brands/{brand}', [BrandController::class, 'update']);
            Route::delete('brands/{brand}', [BrandController::class, 'destroy']);
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

        // Read Transactions (Supervisor+ & Cashier)
        Route::middleware(['permission:view_sales|create_sales'])->group(function () {
            Route::get('transactions', [TransactionController::class, 'index']); // Paginated history
            Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
        });

        // Cashier Operations (create_sales)
        Route::middleware(['permission:create_sales'])->group(function () {
            Route::post('transactions', [TransactionController::class, 'store']); // Bulk Checkout
        });

        // Reports (view_reports)
        Route::middleware(['permission:view_reports'])->prefix('reports')->group(function () {
            Route::get('summary', [ReportController::class, 'summary']);
            Route::get('sales/daily', [ReportController::class, 'daily']);
        });
    });

    // Inventory Management Routes
    Route::middleware(['auth:sanctum'])->prefix('inventory')->group(function () {
        // Inventory Read-Only (Supervisor+)
        Route::middleware(['permission:view_inventory|manage_inventory'])->group(function () {
            Route::get('movements', [StockMovementController::class, 'index']);
            Route::get('movements/{productId}', [StockMovementController::class, 'showByProduct']);
            Route::get('opname', [StockOpnameController::class, 'index']);
            Route::get('opname/{id}', [StockOpnameController::class, 'show']);
        });

        // Operations (Manager+)
        Route::middleware(['permission:manage_inventory'])->group(function () {
            Route::post('adjustment', [StockAdjustmentController::class, 'store']);

            Route::post('opname', [StockOpnameController::class, 'store']);
            Route::put('opname/{id}', [StockOpnameController::class, 'update']);
            Route::delete('opname/{id}', [StockOpnameController::class, 'destroy']);
        });

        // Suppliers Read-Only
        Route::middleware(['permission:view_suppliers|manage_suppliers'])->group(function () {
            Route::get('suppliers/all', [SupplierController::class, 'all']);
            Route::get('suppliers', [SupplierController::class, 'index']);
            Route::get('suppliers/{id}', [SupplierController::class, 'show']);
        });

        // Suppliers Write/Modify
        Route::middleware(['permission:manage_suppliers'])->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store']);
            Route::put('suppliers/{id}', [SupplierController::class, 'update']);
            Route::delete('suppliers/{id}', [SupplierController::class, 'destroy']);
        });
    });

    // Purchase Menu (Pemesanan, Penerimaan, Pembayaran, Retur)
    Route::middleware(['auth:sanctum'])->prefix('purchase')->group(function () {
        // 1. Purchase Orders (Pemesanan)
        Route::middleware(['permission:view_purchase|manage_purchase'])->group(function () {
            Route::get('order/outstanding', [PurchaseOrderController::class, 'outstanding']);
            Route::get('orders/outstanding', [PurchaseOrderController::class, 'outstanding']);
            Route::get('order/{id}/receivings', [PurchaseOrderController::class, 'receivings']);
            Route::get('orders/{id}/receivings', [PurchaseOrderController::class, 'receivings']);
            Route::get('order', [PurchaseOrderController::class, 'index']);
            Route::get('orders', [PurchaseOrderController::class, 'index']);
            Route::get('order/{id}', [PurchaseOrderController::class, 'show']);
            Route::get('orders/{id}', [PurchaseOrderController::class, 'show']);
        });
        Route::middleware(['permission:manage_purchase'])->group(function () {
            Route::post('order', [PurchaseOrderController::class, 'store']);
            Route::post('orders', [PurchaseOrderController::class, 'store']);
            Route::put('order/{id}', [PurchaseOrderController::class, 'update']);
            Route::put('orders/{id}', [PurchaseOrderController::class, 'update']);
            Route::delete('order/{id}', [PurchaseOrderController::class, 'destroy']);
            Route::delete('orders/{id}', [PurchaseOrderController::class, 'destroy']);
            Route::post('order/{id}/finalize', [PurchaseOrderController::class, 'finalize']);
            Route::post('orders/{id}/finalize', [PurchaseOrderController::class, 'finalize']);
            Route::post('order/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::put('order/{id}/items', [PurchaseOrderController::class, 'updateItems']);
            Route::put('orders/{id}/items', [PurchaseOrderController::class, 'updateItems']);
        });

        // 2. Receivings (Penerimaan)
        Route::middleware(['permission:view_purchase|manage_purchase'])->group(function () {
            Route::get('receiving', [StockReceivingController::class, 'index']);
            Route::get('receivings', [StockReceivingController::class, 'index']);
            Route::get('receiving/{id}', [StockReceivingController::class, 'show']);
            Route::get('receivings/{id}', [StockReceivingController::class, 'show']);
        });
        Route::middleware(['permission:manage_purchase'])->group(function () {
            Route::post('receiving', [StockReceivingController::class, 'store']);
            Route::post('receivings', [StockReceivingController::class, 'store']);
            Route::put('receiving/{id}', [StockReceivingController::class, 'update']);
            Route::put('receivings/{id}', [StockReceivingController::class, 'update']);
            Route::delete('receiving/{id}', [StockReceivingController::class, 'destroy']);
            Route::delete('receivings/{id}', [StockReceivingController::class, 'destroy']);
            Route::patch('receiving/{id}/payment-status', [StockReceivingController::class, 'updatePaymentStatus']);
            Route::patch('receivings/{id}/payment-status', [StockReceivingController::class, 'updatePaymentStatus']);
            Route::post('receiving/{id}/complete', [StockReceivingController::class, 'complete']);
            Route::post('receivings/{id}/complete', [StockReceivingController::class, 'complete']);
            Route::post('receiving/compare-prices', [StockReceivingController::class, 'comparePrices']);
            Route::post('receivings/compare-prices', [StockReceivingController::class, 'comparePrices']);
            Route::post('receiving/scan', [StockReceivingController::class, 'scan']);
            Route::post('receivings/scan', [StockReceivingController::class, 'scan']);
            Route::put('receiving/{id}/items', [StockReceivingController::class, 'updateItems']);
            Route::put('receivings/{id}/items', [StockReceivingController::class, 'updateItems']);
        });

        // 3. Payments (Pembayaran)
        Route::middleware(['permission:view_purchase|manage_purchase'])->group(function () {
            Route::get('payment/outstanding', [ReceivingPaymentController::class, 'outstanding']);
            Route::get('payments/outstanding', [ReceivingPaymentController::class, 'outstanding']);
            Route::get('receiving/{id}/payment-summary', [ReceivingPaymentController::class, 'paymentSummary']);
            Route::get('receivings/{id}/payment-summary', [ReceivingPaymentController::class, 'paymentSummary']);
            Route::get('payment', [ReceivingPaymentController::class, 'index']);
            Route::get('payments', [ReceivingPaymentController::class, 'index']);
            Route::get('payment/{id}', [ReceivingPaymentController::class, 'show']);
            Route::get('payments/{id}', [ReceivingPaymentController::class, 'show']);
        });
        Route::middleware(['permission:manage_purchase'])->group(function () {
            Route::post('payment', [ReceivingPaymentController::class, 'store']);
            Route::post('payments', [ReceivingPaymentController::class, 'store']);
            Route::put('payment/{id}', [ReceivingPaymentController::class, 'update']);
            Route::put('payments/{id}', [ReceivingPaymentController::class, 'update']);
            Route::delete('payment/{id}', [ReceivingPaymentController::class, 'destroy']);
            Route::delete('payments/{id}', [ReceivingPaymentController::class, 'destroy']);
        });

        // 4. Returns (Return)
        Route::middleware(['permission:view_purchase|manage_purchase'])->group(function () {
            Route::get('receiving/{id}/returnable-items', [PurchaseReturnController::class, 'returnableItems']);
            Route::get('receivings/{id}/returnable-items', [PurchaseReturnController::class, 'returnableItems']);
            Route::get('return', [PurchaseReturnController::class, 'index']);
            Route::get('returns', [PurchaseReturnController::class, 'index']);
            Route::get('return/{id}', [PurchaseReturnController::class, 'show']);
            Route::get('returns/{id}', [PurchaseReturnController::class, 'show']);
        });
        Route::middleware(['permission:manage_purchase'])->group(function () {
            Route::post('return', [PurchaseReturnController::class, 'store']);
            Route::post('returns', [PurchaseReturnController::class, 'store']);
            Route::put('return/{id}', [PurchaseReturnController::class, 'update']);
            Route::put('returns/{id}', [PurchaseReturnController::class, 'update']);
            Route::delete('return/{id}', [PurchaseReturnController::class, 'destroy']);
            Route::delete('returns/{id}', [PurchaseReturnController::class, 'destroy']);
            Route::post('return/{id}/finalize', [PurchaseReturnController::class, 'finalize']);
            Route::post('returns/{id}/finalize', [PurchaseReturnController::class, 'finalize']);
            Route::post('return/scan', [PurchaseReturnController::class, 'scan']);
            Route::post('returns/scan', [PurchaseReturnController::class, 'scan']);
        });
    });
});
