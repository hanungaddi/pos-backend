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

        // Filter purchase_order_id
        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', $request->integer('purchase_order_id'));
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
        $status = 'draft';

        $receiving = DB::transaction(function () use ($validated, $status, $request) {
            $nomorPenerimaan = $this->generateNomorPenerimaan();

            $receiving = StockReceiving::create([
                'store_id' => $request->user()->store_id,
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'nomor_penerimaan' => $nomorPenerimaan,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier' => $validated['supplier'] ?? null,
                'nomor_faktur' => $validated['nomor_faktur'] ?? null,
                'tanggal_terima' => $validated['tanggal_terima'] ?? now()->toDateString(),
                'catatan' => $validated['catatan'] ?? null,
                'nilai_faktur' => $validated['nilai_faktur'] ?? null,
                'status_pembayaran' => $validated['status_pembayaran'] ?? 'pending',
                'status' => $status,
                'user_id' => $request->user()->id,
            ]);

            // Automatically populate items from linked Purchase Order (PO)
            if ($receiving->purchase_order_id) {
                $po = \App\Models\PurchaseOrder::with('items')->find($receiving->purchase_order_id);
                if ($po) {
                    if (!$receiving->supplier_id) {
                        $receiving->update([
                            'supplier_id' => $po->supplier_id,
                            'supplier' => $po->supplier_name,
                        ]);
                    }

                    foreach ($po->items as $poItem) {
                        $sisa = max(0, $poItem->kuantitas - $poItem->kuantitas_diterima);
                        $receiving->items()->create([
                            'product_id' => $poItem->product_id,
                            'kuantitas' => $sisa,
                            'harga_beli' => $poItem->harga_estimasi,
                            'update_harga_jual' => false,
                        ]);
                    }
                }
            }

            return $this->loadPoQuantityDetails($receiving);
        });

        // Activity log
        ActivityLog::log(
            'create_receiving_draft',
            "Draft receiving '{$receiving->nomor_penerimaan}' was created.",
            $receiving,
            ['new' => $receiving->toArray()]
        );

        return $this->responseSuccess($receiving, 'Penerimaan barang berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (! $receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        $receiving = $this->loadPoQuantityDetails($receiving);

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

        $updatedReceiving = DB::transaction(function () use ($validated, $receiving) {
            $oldPoId = $receiving->purchase_order_id;
            $newPoId = array_key_exists('purchase_order_id', $validated) ? $validated['purchase_order_id'] : $receiving->purchase_order_id;

            $receiving->update([
                'purchase_order_id' => $newPoId,
                'supplier_id' => array_key_exists('supplier_id', $validated) ? $validated['supplier_id'] : $receiving->supplier_id,
                'supplier' => array_key_exists('supplier', $validated) ? $validated['supplier'] : $receiving->supplier,
                'nomor_faktur' => array_key_exists('nomor_faktur', $validated) ? $validated['nomor_faktur'] : $receiving->nomor_faktur,
                'tanggal_terima' => array_key_exists('tanggal_terima', $validated) ? $validated['tanggal_terima'] : $receiving->tanggal_terima,
                'catatan' => array_key_exists('catatan', $validated) ? $validated['catatan'] : $receiving->catatan,
                'nilai_faktur' => array_key_exists('nilai_faktur', $validated) ? $validated['nilai_faktur'] : $receiving->nilai_faktur,
                'status_pembayaran' => array_key_exists('status_pembayaran', $validated) ? $validated['status_pembayaran'] : $receiving->status_pembayaran,
            ]);

            // If purchase_order_id changed, delete old items
            if ($newPoId != $oldPoId) {
                $receiving->items()->delete();
            }

            // If purchase_order_id is set and (changed or items are empty), populate items
            if ($newPoId && ($newPoId != $oldPoId || $receiving->items()->count() === 0)) {
                $po = \App\Models\PurchaseOrder::with('items')->find($newPoId);
                if ($po) {
                    if (!$receiving->supplier_id) {
                        $receiving->update([
                            'supplier_id' => $po->supplier_id,
                            'supplier' => $po->supplier_name,
                        ]);
                    }

                    foreach ($po->items as $poItem) {
                        $sisa = max(0, $poItem->kuantitas - $poItem->kuantitas_diterima);
                        $receiving->items()->create([
                            'product_id' => $poItem->product_id,
                            'kuantitas' => $sisa,
                            'harga_beli' => $poItem->harga_estimasi,
                            'update_harga_jual' => false,
                        ]);
                    }
                }
            }

            return $this->loadPoQuantityDetails($receiving);
        });

        ActivityLog::log(
            'update_receiving_draft',
            "Draft receiving '{$receiving->nomor_penerimaan}' was updated.",
            $updatedReceiving,
            ['new' => $updatedReceiving->toArray()]
        );

        return $this->responseSuccess($updatedReceiving, 'Penerimaan barang berhasil diperbarui.');
    }

    public function updateItems(Request $request, int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (!$receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        if ($receiving->status === 'completed') {
            return response()->json(['message' => 'Penerimaan barang yang sudah selesai tidak dapat diubah.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.kuantitas' => 'required|integer|min:0',
            'items.*.harga_beli' => 'required|integer|min:0',
            'items.*.update_harga_jual' => 'nullable|boolean',
            'items.*.harga_jual_baru' => 'nullable|integer|min:0',
            'items.*.margin_baru' => 'nullable|numeric|min:0',
        ]);

        $purchaseOrderId = $receiving->purchase_order_id;
        if ($purchaseOrderId) {
            $po = \App\Models\PurchaseOrder::with('items')->find($purchaseOrderId);
            if (!$po) {
                return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
            }

            foreach ($validated['items'] as $itemData) {
                $poItem = $po->items->where('product_id', $itemData['product_id'])->first();
                if ($poItem) {
                    $sisa = max(0, $poItem->kuantitas - $poItem->kuantitas_diterima);
                    if ($itemData['kuantitas'] > $sisa) {
                        return response()->json([
                            'message' => "Kuantitas penerimaan untuk produk {$poItem->product->nama} ({$itemData['kuantitas']}) melebihi sisa PO ({$sisa})."
                        ], 422);
                    }
                }
            }
        }

        $updatedReceiving = DB::transaction(function () use ($validated, $receiving) {
            $receiving->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $receiving->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                    'harga_beli' => $itemData['harga_beli'],
                    'update_harga_jual' => $itemData['update_harga_jual'] ?? false,
                    'harga_jual_baru' => $itemData['harga_jual_baru'] ?? null,
                    'margin_baru' => $itemData['margin_baru'] ?? null,
                ]);
            }

            return $this->loadPoQuantityDetails($receiving);
        });

        ActivityLog::log(
            'update_receiving_items',
            "Items for receiving '{$receiving->nomor_penerimaan}' were updated.",
            $updatedReceiving,
            ['new' => $updatedReceiving->toArray()]
        );

        return $this->responseSuccess($updatedReceiving, 'Item penerimaan barang berhasil diperbarui.');
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

    public function complete(int $id, Request $request): JsonResponse
    {
        $receiving = StockReceiving::find($id);

        if (!$receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        if ($receiving->status === 'completed') {
            return response()->json(['message' => 'Penerimaan barang sudah selesai.'], 422);
        }

        if ($receiving->items()->count() === 0) {
            return response()->json(['message' => 'Tidak dapat menyelesaikan penerimaan barang tanpa item.'], 422);
        }

        if ($receiving->items()->sum('kuantitas') === 0) {
            return response()->json(['message' => 'Tidak dapat menyelesaikan penerimaan barang dengan total kuantitas 0.'], 422);
        }

        $completedReceiving = DB::transaction(function () use ($receiving, $request) {
            $receiving->update([
                'status' => 'completed',
            ]);

            foreach ($receiving->items as $item) {
                // Adjust stock and log movement
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if ($product) {
                    if ($item->kuantitas > 0) {
                        $stokSebelum = $product->stok;
                        $stokSesudah = $stokSebelum + $item->kuantitas;

                        // Update product stock
                        $product->stok = $stokSesudah;

                        // Process pricing updates
                        $product->harga_beli = $item->harga_beli;
                        
                        // Pass details to ProductObserver
                        $product->price_log_sumber = 'receiving';
                        $product->price_log_referensi_id = $receiving->id;

                        $updateHargaJual = filter_var($item->update_harga_jual ?? false, FILTER_VALIDATE_BOOLEAN);
                        if ($updateHargaJual) {
                            if (!empty($item->harga_jual_baru)) {
                                $product->harga_jual = (int)$item->harga_jual_baru;
                                $product->margin = null;
                            } elseif (isset($item->margin_baru) && $item->margin_baru !== '' && $item->margin_baru !== null) {
                                $product->margin = (float)$item->margin_baru;
                                $product->harga_jual = 0; // force recalculation
                            } else {
                                $product->harga_jual = 0; // force recalculation based on existing margin
                            }
                        }
                        $product->save();

                        // Log stock movement
                        StockMovement::create([
                            'store_id' => $request->user()->store_id,
                            'product_id' => $product->id,
                            'tipe' => 'receive',
                            'kuantitas' => $item->kuantitas,
                            'stok_sebelum' => $stokSebelum,
                            'stok_sesudah' => $stokSesudah,
                            'referensi_id' => $receiving->id,
                            'referensi_tipe' => 'receiving',
                            'alasan' => 'Penerimaan barang dari supplier (finalisasi)',
                            'user_id' => $request->user()->id,
                        ]);
                    }

                    // Update PO item quantity received
                    if ($receiving->purchase_order_id && $item->kuantitas > 0) {
                        $poItem = \App\Models\PurchaseOrderItem::where('purchase_order_id', $receiving->purchase_order_id)
                            ->where('product_id', $product->id)
                            ->first();
                        if ($poItem) {
                            $poItem->increment('kuantitas_diterima', $item->kuantitas);
                        } else {
                            // Dynamically add new item to the PO
                            \App\Models\PurchaseOrderItem::create([
                                'purchase_order_id' => $receiving->purchase_order_id,
                                'product_id' => $product->id,
                                'kuantitas' => $item->kuantitas,
                                'kuantitas_diterima' => $item->kuantitas,
                                'harga_estimasi' => $item->harga_beli,
                            ]);

                            // Update PO estimation value
                            $po = \App\Models\PurchaseOrder::find($receiving->purchase_order_id);
                            if ($po) {
                                $po->increment('nilai_estimasi', $item->kuantitas * $item->harga_beli);
                            }
                        }
                    }
                }
            }

            // Update PO status
            if ($receiving->purchase_order_id) {
                $po = \App\Models\PurchaseOrder::with('items')->find($receiving->purchase_order_id);
                if ($po) {
                    $allReceived = true;
                    $anyReceived = false;
                    foreach ($po->items as $poItem) {
                        if ($poItem->kuantitas_diterima < $poItem->kuantitas) {
                            $allReceived = false;
                        }
                        if ($poItem->kuantitas_diterima > 0) {
                            $anyReceived = true;
                        }
                    }
                    if ($allReceived) {
                        $po->status = 'received';
                    } elseif ($anyReceived) {
                        $po->status = 'partially_received';
                    } else {
                        $po->status = 'ordered';
                    }
                    $po->save();
                }
            }

            return $this->loadPoQuantityDetails($receiving);
        });

        ActivityLog::log(
            'finalize_receiving',
            "Stock receiving '{$receiving->nomor_penerimaan}' was finalized.",
            $completedReceiving,
            ['new' => $completedReceiving->toArray()]
        );

        return $this->responseSuccess($completedReceiving, 'Penerimaan barang berhasil diselesaikan.');
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => 'required|string',
            'purchase_order_id' => 'nullable|integer|exists:purchase_orders,id',
        ]);

        $product = Product::where('barcode', $validated['barcode'])->first();
        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        $poItemInfo = null;
        if (!empty($validated['purchase_order_id'])) {
            $poItem = \App\Models\PurchaseOrderItem::where('purchase_order_id', $validated['purchase_order_id'])
                ->where('product_id', $product->id)
                ->first();
            
            if ($poItem) {
                $poItemInfo = [
                    'kuantitas_dipesan' => $poItem->kuantitas,
                    'kuantitas_sudah_diterima' => $poItem->kuantitas_diterima,
                    'sisa' => max(0, $poItem->kuantitas - $poItem->kuantitas_diterima),
                    'harga_estimasi' => $poItem->harga_estimasi,
                ];
            }
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'nama' => $product->nama,
                'barcode' => $product->barcode,
                'harga_beli_terakhir' => $product->harga_beli,
                'harga_jual' => $product->harga_jual,
            ],
            'po_item' => $poItemInfo,
        ]);
    }

    private function generateNomorPenerimaan(): string
    {
        do {
            $number = 'RCV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockReceiving::where('nomor_penerimaan', $number)->exists());

        return $number;
    }

    private function loadPoQuantityDetails(StockReceiving $receiving): StockReceiving
    {
        $receiving->load(['items.product', 'user', 'supplier_relationship']);

        if ($receiving->purchase_order_id) {
            $poItems = \App\Models\PurchaseOrderItem::where('purchase_order_id', $receiving->purchase_order_id)
                ->get()
                ->keyBy('product_id');

            foreach ($receiving->items as $item) {
                $poItem = $poItems->get($item->product_id);
                if ($poItem) {
                    $item->setAttribute('kuantitas_po', $poItem->kuantitas);
                    $item->setAttribute('kuantitas_diterima_po', $poItem->kuantitas_diterima);
                    $item->setAttribute('sisa_belum_diterima_po', max(0, $poItem->kuantitas - $poItem->kuantitas_diterima));
                } else {
                    $item->setAttribute('kuantitas_po', 0);
                    $item->setAttribute('kuantitas_diterima_po', 0);
                    $item->setAttribute('sisa_belum_diterima_po', 0);
                }
            }
        } else {
            foreach ($receiving->items as $item) {
                $item->setAttribute('kuantitas_po', 0);
                $item->setAttribute('kuantitas_diterima_po', 0);
                $item->setAttribute('sisa_belum_diterima_po', 0);
            }
        }

        return $receiving;
    }
}
