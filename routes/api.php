<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::apiResource('products', ProductController::class);

Route::get('reports/summary', [SaleController::class, 'summary']);
Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);
