<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseReturnRequest;
use App\Models\PurchaseReturn;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReturn::query()->with(['user', 'supplier', 'stockReceiving']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('stock_receiving_id')) {
            $query->where('stock_receiving_id', $request->integer('stock_receiving_id'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('tanggal_retur', '>=', $request->string('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('tanggal_retur', '<=', $request->string('end_date'));
        }

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where(function ($q) use ($keyword) {
                $q->where('nomor_retur', 'like', "%{$keyword}%")
                  ->orWhereHas('supplier', function ($supQuery) use ($keyword) {
                      $supQuery->where('nama', 'like', "%{$keyword}%");
                  });
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'nomor_retur', 'tanggal_retur', 'total_nominal'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $returns = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($returns);
    }

    public function store(PurchaseReturnRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $return = DB::transaction(function () use ($validated, $request) {
            $nomorRetur = $this->generateNomorRetur();

            $totalNominal = 0;
            foreach ($validated['items'] as $itemData) {
                $totalNominal += $itemData['kuantitas'] * $itemData['harga_beli'];
            }

            $return = PurchaseReturn::create([
                'store_id' => $request->user()->store_id,
                'nomor_retur' => $nomorRetur,
                'stock_receiving_id' => $validated['stock_receiving_id'] ?? null,
                'supplier_id' => $validated['supplier_id'],
                'tanggal_retur' => $validated['tanggal_retur'],
                'total_nominal' => $totalNominal,
                'catatan' => $validated['catatan'] ?? null,
                'status' => 'draft',
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $itemData) {
                $return->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                    'harga_beli' => $itemData['harga_beli'],
                ]);
            }

            return $return->load(['items.product', 'user', 'supplier', 'stockReceiving']);
        });

        ActivityLog::log(
            'create_purchase_return_draft',
            "Draft Purchase Return '{$return->nomor_retur}' was created.",
            $return,
            ['new' => $return->toArray()]
        );

        return $this->responseSuccess($return, 'Draft retur pembelian berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $return = PurchaseReturn::with(['items.product', 'user', 'supplier', 'stockReceiving'])->find($id);

        if (!$return) {
            return response()->json(['message' => 'Data retur pembelian tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($return, 'Detail retur pembelian.');
    }

    public function update(PurchaseReturnRequest $request, int $id): JsonResponse
    {
        $return = PurchaseReturn::find($id);

        if (!$return) {
            return response()->json(['message' => 'Data retur pembelian tidak ditemukan.'], 404);
        }

        if ($return->status !== 'draft') {
            return response()->json(['message' => 'Hanya retur pembelian dengan status draft yang dapat diubah.'], 422);
        }

        $validated = $request->validated();

        $updatedReturn = DB::transaction(function () use ($validated, $return) {
            $totalNominal = 0;
            foreach ($validated['items'] as $itemData) {
                $totalNominal += $itemData['kuantitas'] * $itemData['harga_beli'];
            }

            $return->update([
                'stock_receiving_id' => $validated['stock_receiving_id'] ?? $return->stock_receiving_id,
                'supplier_id' => $validated['supplier_id'] ?? $return->supplier_id,
                'tanggal_retur' => $validated['tanggal_retur'] ?? $return->tanggal_retur,
                'total_nominal' => $totalNominal,
                'catatan' => $validated['catatan'] ?? $return->catatan,
            ]);

            $return->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $return->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                    'harga_beli' => $itemData['harga_beli'],
                ]);
            }

            return $return->load(['items.product', 'user', 'supplier', 'stockReceiving']);
        });

        ActivityLog::log(
            'update_purchase_return_draft',
            "Draft Purchase Return '{$return->nomor_retur}' was updated.",
            $updatedReturn,
            ['new' => $updatedReturn->toArray()]
        );

        return $this->responseSuccess($updatedReturn, 'Retur pembelian berhasil diperbarui.');
    }

    public function destroy(int $id): JsonResponse
    {
        $return = PurchaseReturn::find($id);

        if (!$return) {
            return response()->json(['message' => 'Data retur pembelian tidak ditemukan.'], 404);
        }

        if ($return->status !== 'draft') {
            return response()->json(['message' => 'Hanya retur pembelian dengan status draft yang dapat dihapus.'], 422);
        }

        $nomorRetur = $return->nomor_retur;
        $oldData = $return->toArray();

        DB::transaction(function () use ($return) {
            $return->items()->delete();
            $return->delete();
        });

        ActivityLog::log(
            'delete_purchase_return_draft',
            "Draft Purchase Return '{$nomorRetur}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Draft retur pembelian berhasil dihapus.');
    }

    public function finalize(Request $request, int $id): JsonResponse
    {
        $return = PurchaseReturn::with('items')->find($id);

        if (!$return) {
            return response()->json(['message' => 'Data retur pembelian tidak ditemukan.'], 404);
        }

        if ($return->status !== 'draft') {
            return response()->json(['message' => 'Hanya retur pembelian dengan status draft yang dapat difinalisasi.'], 422);
        }

        $validated = $request->validate([
            'impact_type' => 'required|string|in:refund,credit',
            'cash_account_id' => 'required_if:impact_type,refund|nullable|integer|exists:cash_accounts,id',
            'stock_receiving_id' => 'required_if:impact_type,credit|nullable|integer|exists:stock_receivings,id',
        ]);

        $finalizedReturn = DB::transaction(function () use ($validated, $return, $request) {
            // 1. Deduct stock and log stock movement
            foreach ($return->items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $stokSebelum = $product->stok;
                    $stokSesudah = $stokSebelum - $item->kuantitas;

                    $product->stok = $stokSesudah;
                    $product->save();

                    StockMovement::create([
                        'store_id' => $request->user()->store_id,
                        'product_id' => $product->id,
                        'tipe' => 'void', // using standard type for deductions
                        'kuantitas' => -$item->kuantitas,
                        'stok_sebelum' => $stokSebelum,
                        'stok_sesudah' => $stokSesudah,
                        'referensi_id' => $return->id,
                        'referensi_tipe' => 'purchase_return',
                        'alasan' => 'Retur barang ke supplier: ' . $return->nomor_retur,
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            // 2. Log transaction for refund / credit
            $nomorTransaksi = 'TX-RET-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
            
            if ($validated['impact_type'] === 'refund') {
                Transaction::create([
                    'store_id' => $request->user()->store_id,
                    'user_id' => $request->user()->id,
                    'nomor_transaksi' => $nomorTransaksi,
                    'tipe' => 'supplier_return_refund',
                    'cash_account_id' => $validated['cash_account_id'],
                    'kategori' => 'pembelian_supplier',
                    'referensi_id' => $return->id,
                    'referensi_tipe' => 'purchase_return',
                    'total' => $return->total_nominal,
                    'status' => 'completed',
                    'metode_pembayaran' => 'cash',
                ]);
            } else {
                // credit reduces outstanding invoice debt
                $receivingId = $validated['stock_receiving_id'] ?? $return->stock_receiving_id;
                
                Transaction::create([
                    'store_id' => $request->user()->store_id,
                    'user_id' => $request->user()->id,
                    'nomor_transaksi' => $nomorTransaksi,
                    'tipe' => 'supplier_return_credit',
                    'cash_account_id' => null, // does not affect cash balance
                    'kategori' => 'pembelian_supplier',
                    'referensi_id' => $receivingId,
                    'referensi_tipe' => 'receiving',
                    'total' => $return->total_nominal,
                    'status' => 'completed',
                    'metode_pembayaran' => 'other',
                ]);
            }

            // 3. Mark return status as completed
            $return->update([
                'status' => 'completed',
                'stock_receiving_id' => $validated['stock_receiving_id'] ?? $return->stock_receiving_id,
            ]);

            return $return;
        });

        ActivityLog::log(
            'finalize_purchase_return',
            "Purchase Return '{$return->nomor_retur}' was finalized.",
            $finalizedReturn,
            ['new' => $finalizedReturn->toArray()]
        );

        return $this->responseSuccess($finalizedReturn->load(['items.product', 'user', 'supplier']), 'Retur pembelian berhasil difinalisasi.');
    }

    private function generateNomorRetur(): string
    {
        do {
            $number = 'PRT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (PurchaseReturn::where('nomor_retur', $number)->exists());

        return $number;
    }
}
