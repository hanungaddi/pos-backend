<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $movement = DB::transaction(function () use ($validated, $request) {
            $product = Product::where('id', $validated['product_id'])->lockForUpdate()->first();

            $stokSebelum = $product->stok;
            $stokSesudah = $stokSebelum + $validated['kuantitas'];

            // Update product stock
            $product->stok = $stokSesudah;
            $product->save();

            // Log stock movement
            $movement = StockMovement::create([
                'store_id' => $request->user()->store_id,
                'product_id' => $product->id,
                'tipe' => 'adjustment',
                'kuantitas' => $validated['kuantitas'],
                'stok_sebelum' => $stokSebelum,
                'stok_sesudah' => $stokSesudah,
                'referensi_id' => null,
                'referensi_tipe' => 'manual',
                'alasan' => $validated['alasan'],
                'user_id' => $request->user()->id,
            ]);

            return $movement->load('product');
        });

        return $this->responseSuccess($movement, 'Penyesuaian stok berhasil disimpan.', 201);
    }
}
