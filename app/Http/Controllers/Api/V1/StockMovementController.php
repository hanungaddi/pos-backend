<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockMovement::query()->with(['product', 'user']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->string('tipe'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to')->toDateString());
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'tipe', 'kuantitas', 'stok_sebelum', 'stok_sesudah'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $movements = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($movements);
    }

    public function showByProduct(int $productId, Request $request): JsonResponse
    {
        $query = StockMovement::query()
            ->where('product_id', $productId)
            ->with(['product', 'user']);

        if ($request->filled('tipe')) {
            $query->where('tipe', $request->string('tipe'));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'tipe', 'kuantitas', 'stok_sebelum', 'stok_sesudah'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $movements = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($movements);
    }
}
