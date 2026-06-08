<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockReceivingRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReceiving;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockReceivingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockReceiving::query()->with(['user', 'supplier_relationship']);

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // Filter supplier_id
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where(function ($q) use ($keyword) {
                $q->where('nomor_penerimaan', 'like', "%{$keyword}%")
                  ->orWhere('supplier', 'like', "%{$keyword}%")
                  ->orWhere('nomor_faktur', 'like', "%{$keyword}%")
                  ->orWhereHas('supplier_relationship', function ($supQuery) use ($keyword) {
                      $supQuery->where('nama', 'like', "%{$keyword}%");
                  });
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
        $status = $validated['status'] ?? 'completed';

        $receiving = DB::transaction(function () use ($validated, $status, $request) {
            $nomorPenerimaan = $this->generateNomorPenerimaan();

            $receiving = StockReceiving::create([
                'store_id' => $request->user()->store_id,
                'nomor_penerimaan' => $nomorPenerimaan,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier' => $validated['supplier'] ?? null,
                'nomor_faktur' => $validated['nomor_faktur'] ?? null,
                'catatan' => $validated['catatan'] ?? null,
                'nilai_faktur' => $validated['nilai_faktur'] ?? null,
                'status_pembayaran' => $validated['status_pembayaran'] ?? 'pending',
                'status' => $status,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $itemData) {
                // Create receiving item
                $receiving->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                ]);

                // Only adjust stock and log movement if completed immediately
                if ($status === 'completed') {
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
                    }
                }
            }

            return $receiving->load(['items.product', 'user', 'supplier_relationship']);
        });

        // Activity log
        $action = $status === 'draft' ? 'create_receiving_draft' : 'create_receiving';
        $desc = $status === 'draft' ? 'Draft receiving ' : 'Stock receiving ';
        ActivityLog::log(
            $action,
            "{$desc} '{$receiving->nomor_penerimaan}' was created.",
            $receiving,
            ['new' => $receiving->toArray()]
        );

        return $this->responseSuccess($receiving, 'Penerimaan barang berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $receiving = StockReceiving::with(['items.product', 'user', 'supplier_relationship'])->find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($receiving, 'Detail penerimaan barang.');
    }

    public function update(StockReceivingRequest $request, int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        if ($receiving->status === 'completed') {
            return response()->json(['message' => 'Penerimaan barang yang sudah selesai tidak dapat diubah.'], 422);
        }

        $validated = $request->validated();
        $newStatus = $validated['status'] ?? 'draft';

        $updatedReceiving = DB::transaction(function () use ($validated, $newStatus, $receiving, $request) {
            $receiving->update([
                'supplier_id' => $validated['supplier_id'] ?? $receiving->supplier_id,
                'supplier' => $validated['supplier'] ?? $receiving->supplier,
                'nomor_faktur' => $validated['nomor_faktur'] ?? $receiving->nomor_faktur,
                'catatan' => $validated['catatan'] ?? $receiving->catatan,
                'nilai_faktur' => $validated['nilai_faktur'] ?? $receiving->nilai_faktur,
                'status_pembayaran' => $validated['status_pembayaran'] ?? $receiving->status_pembayaran,
                'status' => $newStatus,
            ]);

            // Rebuild items
            $receiving->items()->delete();

            foreach ($validated['items'] as $itemData) {
                // Re-create items
                $receiving->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                ]);

                // If finalizing to completed, adjust stock and log movement
                if ($newStatus === 'completed') {
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
                            'alasan' => 'Penerimaan barang dari supplier (finalisasi)',
                            'user_id' => $request->user()->id,
                        ]);
                    }
                }
            }

            return $receiving->load(['items.product', 'user', 'supplier_relationship']);
        });

        // Activity log
        if ($newStatus === 'completed') {
            ActivityLog::log(
                'finalize_receiving',
                "Stock receiving '{$receiving->nomor_penerimaan}' was finalized.",
                $updatedReceiving,
                ['new' => $updatedReceiving->toArray()]
            );
        } else {
            ActivityLog::log(
                'update_receiving_draft',
                "Draft receiving '{$receiving->nomor_penerimaan}' was updated.",
                $updatedReceiving,
                ['new' => $updatedReceiving->toArray()]
            );
        }

        return $this->responseSuccess($updatedReceiving, 'Penerimaan barang berhasil diperbarui.');
    }

    public function destroy(int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        if ($receiving->status === 'completed') {
            return response()->json(['message' => 'Penerimaan barang yang sudah selesai tidak dapat dihapus.'], 422);
        }

        $nomorPenerimaan = $receiving->nomor_penerimaan;
        $oldData = $receiving->toArray();

        DB::transaction(function () use ($receiving) {
            $receiving->items()->delete();
            $receiving->delete();
        });

        ActivityLog::log(
            'delete_receiving_draft',
            "Draft receiving '{$nomorPenerimaan}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Draft penerimaan berhasil dihapus.');
    }

    public function updatePaymentStatus(Request $request, int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'status_pembayaran' => 'required|string|in:pending,paid',
        ]);

        $oldStatus = $receiving->status_pembayaran;
        $receiving->update([
            'status_pembayaran' => $validated['status_pembayaran'],
        ]);

        ActivityLog::log(
            'update_receiving_payment',
            "Invoice '{$receiving->nomor_faktur}' payment status updated from '{$oldStatus}' to '{$receiving->status_pembayaran}'.",
            $receiving,
            ['old' => ['status_pembayaran' => $oldStatus], 'new' => ['status_pembayaran' => $receiving->status_pembayaran]]
        );

        return $this->responseSuccess($receiving, 'Status pembayaran faktur berhasil diperbarui.');
    }

    private function generateNomorPenerimaan(): string
    {
        do {
            $number = 'RCV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockReceiving::where('nomor_penerimaan', $number)->exists());

        return $number;
    }
}
