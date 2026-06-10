<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()->with(['user', 'supplier']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('tanggal_po', '>=', $request->string('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('tanggal_po', '<=', $request->string('end_date'));
        }

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where(function ($q) use ($keyword) {
                $q->where('nomor_po', 'like', "%{$keyword}%")
                  ->orWhere('supplier_name', 'like', "%{$keyword}%")
                  ->orWhereHas('supplier', function ($supQuery) use ($keyword) {
                      $supQuery->where('nama', 'like', "%{$keyword}%");
                  });
            });
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'nomor_po', 'tanggal_po', 'nilai_estimasi'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $pos = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($pos);
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $po = DB::transaction(function () use ($validated, $request) {
            $nomorPo = $this->generateNomorPo();

            // Calculate estimated value
            $nilaiEstimasi = 0;
            foreach ($validated['items'] as $itemData) {
                $nilaiEstimasi += $itemData['kuantitas'] * $itemData['harga_estimasi'];
            }

            $po = PurchaseOrder::create([
                'store_id' => $request->user()->store_id,
                'nomor_po' => $nomorPo,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'tanggal_po' => $validated['tanggal_po'],
                'status' => 'draft',
                'nilai_estimasi' => $nilaiEstimasi,
                'catatan' => $validated['catatan'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $itemData) {
                $po->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                    'kuantitas_diterima' => 0,
                    'harga_estimasi' => $itemData['harga_estimasi'],
                ]);
            }

            return $po->load(['items.product', 'user', 'supplier']);
        });

        ActivityLog::log(
            'create_purchase_order_draft',
            "Draft Purchase Order '{$po->nomor_po}' was created.",
            $po,
            ['new' => $po->toArray()]
        );

        return $this->responseSuccess($po, 'Draft Purchase Order berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $po = PurchaseOrder::with(['items.product', 'user', 'supplier'])->find($id);

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($po, 'Detail Purchase Order.');
    }

    public function update(PurchaseOrderRequest $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Hanya Purchase Order dengan status draft yang dapat diubah.'], 422);
        }

        $validated = $request->validated();

        $updatedPo = DB::transaction(function () use ($validated, $po) {
            // Calculate estimated value
            $nilaiEstimasi = 0;
            foreach ($validated['items'] as $itemData) {
                $nilaiEstimasi += $itemData['kuantitas'] * $itemData['harga_estimasi'];
            }

            $po->update([
                'supplier_id' => $validated['supplier_id'] ?? $po->supplier_id,
                'supplier_name' => $validated['supplier_name'] ?? $po->supplier_name,
                'tanggal_po' => $validated['tanggal_po'] ?? $po->tanggal_po,
                'nilai_estimasi' => $nilaiEstimasi,
                'catatan' => $validated['catatan'] ?? $po->catatan,
            ]);

            $po->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $po->items()->create([
                    'product_id' => $itemData['product_id'],
                    'kuantitas' => $itemData['kuantitas'],
                    'kuantitas_diterima' => 0,
                    'harga_estimasi' => $itemData['harga_estimasi'],
                ]);
            }

            return $po->load(['items.product', 'user', 'supplier']);
        });

        ActivityLog::log(
            'update_purchase_order_draft',
            "Draft Purchase Order '{$po->nomor_po}' was updated.",
            $updatedPo,
            ['new' => $updatedPo->toArray()]
        );

        return $this->responseSuccess($updatedPo, 'Purchase Order berhasil diperbarui.');
    }

    public function destroy(int $id): JsonResponse
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Hanya Purchase Order dengan status draft yang dapat dihapus.'], 422);
        }

        $nomorPo = $po->nomor_po;
        $oldData = $po->toArray();

        DB::transaction(function () use ($po) {
            $po->items()->delete();
            $po->delete();
        });

        ActivityLog::log(
            'delete_purchase_order_draft',
            "Draft Purchase Order '{$nomorPo}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Draft Purchase Order berhasil dihapus.');
    }

    public function finalize(int $id): JsonResponse
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
        }

        if ($po->status !== 'draft') {
            return response()->json(['message' => 'Hanya Purchase Order dengan status draft yang dapat difinalisasi.'], 422);
        }

        $po->update(['status' => 'ordered']);

        ActivityLog::log(
            'finalize_purchase_order',
            "Purchase Order '{$po->nomor_po}' was finalized to 'ordered'.",
            $po,
            ['new' => $po->toArray()]
        );

        return $this->responseSuccess($po->load(['items.product', 'user', 'supplier']), 'Purchase Order berhasil difinalisasi.');
    }

    public function cancel(int $id): JsonResponse
    {
        $po = PurchaseOrder::find($id);

        if (!$po) {
            return response()->json(['message' => 'Purchase Order tidak ditemukan.'], 404);
        }

        if ($po->status === 'received') {
            return response()->json(['message' => 'Purchase Order yang sudah diterima tidak dapat dibatalkan.'], 422);
        }

        if ($po->status === 'cancelled') {
            return response()->json(['message' => 'Purchase Order sudah dibatalkan.'], 422);
        }

        $po->update(['status' => 'cancelled']);

        ActivityLog::log(
            'cancel_purchase_order',
            "Purchase Order '{$po->nomor_po}' was cancelled.",
            $po,
            ['new' => $po->toArray()]
        );

        return $this->responseSuccess($po->load(['items.product', 'user', 'supplier']), 'Purchase Order berhasil dibatalkan.');
    }

    private function generateNomorPo(): string
    {
        do {
            $number = 'PO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (PurchaseOrder::where('nomor_po', $number)->exists());

        return $number;
    }
}
