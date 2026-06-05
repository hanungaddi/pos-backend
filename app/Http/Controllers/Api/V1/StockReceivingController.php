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

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('nomor_penerimaan', 'like', "%{$search}%")
                  ->orWhere('supplier', 'like', "%{$search}%")
                  ->orWhere('nomor_faktur', 'like', "%{$search}%");
            });
        }

        $receivings = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json($receivings);
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

        return response()->json([
            'message' => 'Penerimaan barang berhasil disimpan.',
            'data' => $receiving
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $receiving = StockReceiving::with(['items.product', 'user'])->find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        return response()->json(['data' => $receiving]);
    }

    private function generateNomorPenerimaan(): string
    {
        do {
            $number = 'RCV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockReceiving::where('nomor_penerimaan', $number)->exists());

        return $number;
    }
}
