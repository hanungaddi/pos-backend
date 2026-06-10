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
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
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
                    'harga_beli' => $itemData['harga_beli'],
                ]);

                // Only adjust stock and log movement if completed immediately
                if ($status === 'completed') {
                    $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->first();
                    if ($product) {
                        $stokSebelum = $product->stok;
                        $stokSesudah = $stokSebelum + $itemData['kuantitas'];

                        // Update product stock
                        $product->stok = $stokSesudah;

                        // Process pricing updates
                        $product->harga_beli = $itemData['harga_beli'];
                        
                        // Pass details to ProductObserver
                        $product->price_log_sumber = 'receiving';
                        $product->price_log_referensi_id = $receiving->id;

                        $updateHargaJual = filter_var($itemData['update_harga_jual'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        if ($updateHargaJual) {
                            if (!empty($itemData['harga_jual_baru'])) {
                                $product->harga_jual = (int)$itemData['harga_jual_baru'];
                                $product->margin = null;
                            } elseif (isset($itemData['margin_baru']) && $itemData['margin_baru'] !== '') {
                                $product->margin = (float)$itemData['margin_baru'];
                                $product->harga_jual = 0; // force recalculation
                            } else {
                                $product->harga_jual = 0; // force recalculation based on existing margin
                            }
                        }

                        $product->save();

                        // Update PO item quantity received
                        if ($receiving->purchase_order_id) {
                            $poItem = \App\Models\PurchaseOrderItem::where('purchase_order_id', $receiving->purchase_order_id)
                                ->where('product_id', $product->id)
                                ->first();
                            if ($poItem) {
                                $poItem->increment('kuantitas_diterima', $itemData['kuantitas']);
                            }
                        }

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

            // If completed, update PO status if fully received
            if ($status === 'completed' && $receiving->purchase_order_id) {
                $po = \App\Models\PurchaseOrder::with('items')->find($receiving->purchase_order_id);
                if ($po) {
                    $allReceived = true;
                    foreach ($po->items as $poItem) {
                        if ($poItem->kuantitas_diterima < $poItem->kuantitas) {
                            $allReceived = false;
                            break;
                        }
                    }
                    $po->status = $allReceived ? 'received' : 'ordered';
                    $po->save();
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
                'purchase_order_id' => $validated['purchase_order_id'] ?? $receiving->purchase_order_id,
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
                    'harga_beli' => $itemData['harga_beli'],
                ]);

                // If finalizing to completed, adjust stock and log movement
                if ($newStatus === 'completed') {
                    $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->first();
                    if ($product) {
                        $stokSebelum = $product->stok;
                        $stokSesudah = $stokSebelum + $itemData['kuantitas'];

                        // Update product stock
                        $product->stok = $stokSesudah;

                        // Process pricing updates
                        $product->harga_beli = $itemData['harga_beli'];
                        
                        // Pass details to ProductObserver
                        $product->price_log_sumber = 'receiving';
                        $product->price_log_referensi_id = $receiving->id;

                        $updateHargaJual = filter_var($itemData['update_harga_jual'] ?? false, FILTER_VALIDATE_BOOLEAN);
                        if ($updateHargaJual) {
                            if (!empty($itemData['harga_jual_baru'])) {
                                $product->harga_jual = (int)$itemData['harga_jual_baru'];
                                $product->margin = null;
                            } elseif (isset($itemData['margin_baru']) && $itemData['margin_baru'] !== '') {
                                $product->margin = (float)$itemData['margin_baru'];
                                $product->harga_jual = 0; // force recalculation
                            } else {
                                $product->harga_jual = 0; // force recalculation based on existing margin
                            }
                        }

                        $product->save();

                        // Update PO item quantity received
                        if ($receiving->purchase_order_id) {
                            $poItem = \App\Models\PurchaseOrderItem::where('purchase_order_id', $receiving->purchase_order_id)
                                ->where('product_id', $product->id)
                                ->first();
                            if ($poItem) {
                                $poItem->increment('kuantitas_diterima', $itemData['kuantitas']);
                            }
                        }

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

            // If completed, update PO status if fully received
            if ($newStatus === 'completed' && $receiving->purchase_order_id) {
                $po = \App\Models\PurchaseOrder::with('items')->find($receiving->purchase_order_id);
                if ($po) {
                    $allReceived = true;
                    foreach ($po->items as $poItem) {
                        if ($poItem->kuantitas_diterima < $poItem->kuantitas) {
                            $allReceived = false;
                            break;
                        }
                    }
                    $po->status = $allReceived ? 'received' : 'ordered';
                    $po->save();
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

    public function comparePrices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.harga_beli' => ['required', 'integer', 'min:0'],
        ]);

        $results = [];

        foreach ($validated['items'] as $itemData) {
            $product = Product::find($itemData['product_id']);
            if (!$product) {
                continue;
            }

            $hargaBeliLama = (int) $product->harga_beli;
            $hargaBeliBaru = (int) $itemData['harga_beli'];
            $hargaJualLama = (int) $product->harga_jual;
            $marginLama = (float) $product->margin;

            if ($marginLama > 0) {
                $hargaJualSaran = (int) round($hargaBeliBaru * (1 + $marginLama / 100));
            } else {
                $hargaJualSaran = $hargaJualLama;
            }

            $selisihHargaBeli = $hargaBeliBaru - $hargaBeliLama;
            $perluAlert = $hargaBeliBaru > $hargaBeliLama;

            $results[] = [
                'product_id' => $product->id,
                'nama' => $product->nama,
                'harga_beli_lama' => $hargaBeliLama,
                'harga_beli_baru' => $hargaBeliBaru,
                'harga_jual_lama' => $hargaJualLama,
                'margin_lama' => $marginLama,
                'harga_jual_saran' => $hargaJualSaran,
                'selisih_harga_beli' => $selisihHargaBeli,
                'perlu_alert' => $perluAlert,
            ];
        }

        return response()->json([
            'data' => $results
        ]);
    }

    private function generateNomorPenerimaan(): string
    {
        do {
            $number = 'RCV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockReceiving::where('nomor_penerimaan', $number)->exists());

        return $number;
    }
}
