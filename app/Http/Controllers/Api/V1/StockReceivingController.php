<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockReceivingRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReceivingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockReceiving::query()->with(['user']);

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where(function ($q) use ($keyword) {
                $q->where('nomor_penerimaan', 'like', "%{$keyword}%")
                  ->orWhere('supplier', 'like', "%{$keyword}%")
                  ->orWhere('nomor_faktur', 'like', "%{$keyword}%");
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'nomor_penerimaan', 'supplier', 'nomor_faktur'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $receivings = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($receivings);
    }

    public function store(StockReceivingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $receiving = DB::transaction(function () use ($validated, $request) {
            $nomorPenerimaan = $this->generateNomorPenerimaan();

            $receiving = StockReceiving::create([
                'store_id' => $request->user()->store_id,
                'nomor_penerimaan' => $nomorPenerimaan,
                'supplier' => $validated['supplier'] ?? null,
                'nomor_faktur' => $validated['nomor_faktur'] ?? null,
                'catatan' => $validated['catatan'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $itemData) {
                $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->first();

                if ($product) {
                    $stokSebelum = $product->stok;
                    $stokSesudah = $stokSebelum + $itemData['kuantitas'];

                    // Update product stock
                    $product->increment('stok', $itemData['kuantitas']);

                    // Log stock movement
                    StockMovement::create([
                        'store_id' => $request->user()->store_id,
                        'product_id' => $product->id,
                        'tipe' => 'receive',
                        'kuantitas' => $itemData['kuantitas'],
                        'stok_sebelum' => $stokSebelum,
                        'stok_sesudah' => $stokSesudah,
                        'referensi_id' => $receiving->id,
                        'referensi_tipe' => 'receiving',
                        'alasan' => 'Penerimaan barang dari supplier',
                        'user_id' => $request->user()->id,
                    ]);

                    // Create receiving item
                    $receiving->items()->create([
                        'product_id' => $product->id,
                        'kuantitas' => $itemData['kuantitas'],
                    ]);
                }
            }

            return $receiving->load(['items.product', 'user']);
        });

        return $this->responseSuccess($receiving, 'Penerimaan barang berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $receiving = StockReceiving::with(['items.product', 'user'])->find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($receiving, 'Detail penerimaan barang.');
    }

    private function generateNomorPenerimaan(): string
    {
        do {
            $number = 'RCV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockReceiving::where('nomor_penerimaan', $number)->exists());

        return $number;
    }
}
