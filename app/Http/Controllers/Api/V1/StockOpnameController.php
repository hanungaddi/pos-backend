<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockOpnameStoreRequest;
use App\Http\Requests\StockOpnameUpdateRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockOpnameController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockOpname::query()->with(['user', 'items.product']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where('nomor_opname', 'like', "%{$keyword}%");
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['created_at', 'nomor_opname', 'status'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $opnames = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($opnames);
    }

    public function store(StockOpnameStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $opname = DB::transaction(function () use ($validated, $request) {
            $nomorOpname = $this->generateNomorOpname();

            $opname = StockOpname::create([
                'store_id' => $request->user()->store_id,
                'nomor_opname' => $nomorOpname,
                'catatan' => $validated['catatan'] ?? null,
                'status' => 'draft',
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);

                if ($product) {
                    $stokSistem = $product->stok;
                    $stokFisik = $itemData['stok_fisik'];
                    $selisih = $stokFisik - $stokSistem;

                    $opname->items()->create([
                        'product_id' => $product->id,
                        'stok_sistem' => $stokSistem,
                        'stok_fisik' => $stokFisik,
                        'selisih' => $selisih,
                        'alasan' => $itemData['alasan'] ?? null,
                    ]);
                }
            }

            return $opname->load(['items.product', 'user']);
        });

        ActivityLog::log(
            'create_opname_draft',
            "Draft stock opname '{$opname->nomor_opname}' was created.",
            $opname,
            ['new' => $opname->toArray()]
        );

        return $this->responseSuccess($opname, 'Draft stock opname berhasil disimpan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $opname = StockOpname::with(['items.product', 'user'])->find($id);

        if (! $opname) {
            return response()->json(['message' => 'Data stock opname tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($opname, 'Detail stock opname.');
    }

    public function update(StockOpnameUpdateRequest $request, int $id): JsonResponse
    {
        $opname = StockOpname::find($id);

        if (! $opname) {
            return response()->json(['message' => 'Data stock opname tidak ditemukan.'], 404);
        }

        if ($opname->status === 'completed') {
            return response()->json(['message' => 'Stock opname yang sudah selesai tidak dapat diubah.'], 422);
        }

        $validated = $request->validated();

        $updatedOpname = DB::transaction(function () use ($validated, $opname, $request) {
            $opname->update([
                'catatan' => $validated['catatan'] ?? $opname->catatan,
                'status' => $validated['status'],
                'completed_at' => $validated['status'] === 'completed' ? now() : null,
            ]);

            // Delete existing items to rebuild them
            $opname->items()->delete();

            foreach ($validated['items'] as $itemData) {
                $product = Product::where('id', $itemData['product_id'])->lockForUpdate()->first();

                if ($product) {
                    $stokSistem = $product->stok;
                    $stokFisik = $itemData['stok_fisik'];
                    $selisih = $stokFisik - $stokSistem;

                    $item = $opname->items()->create([
                        'product_id' => $product->id,
                        'stok_sistem' => $stokSistem,
                        'stok_fisik' => $stokFisik,
                        'selisih' => $selisih,
                        'alasan' => $itemData['alasan'] ?? null,
                    ]);

                    // If status is completed, update product stock and log movement
                    if ($validated['status'] === 'completed') {
                        if ($selisih !== 0) {
                            $product->stok = $stokFisik;
                            $product->save();

                            StockMovement::create([
                                'store_id' => $request->user()->store_id,
                                'product_id' => $product->id,
                                'tipe' => 'opname',
                                'kuantitas' => $selisih,
                                'stok_sebelum' => $stokSistem,
                                'stok_sesudah' => $stokFisik,
                                'referensi_id' => $opname->id,
                                'referensi_tipe' => 'opname',
                                'alasan' => $itemData['alasan'] ?? 'Penyesuaian hasil stock opname',
                                'user_id' => $request->user()->id,
                            ]);
                        }
                    }
                }
            }

            return $opname->load(['items.product', 'user']);
        });

        $message = $validated['status'] === 'completed'
            ? 'Stock opname berhasil diselesaikan dan stok telah disesuaikan.'
            : 'Draft stock opname berhasil diperbarui.';

        if ($validated['status'] === 'completed') {
            ActivityLog::log(
                'finalize_opname',
                "Stock opname '{$opname->nomor_opname}' was finalized.",
                $updatedOpname,
                ['new' => $updatedOpname->toArray()]
            );
        } else {
            ActivityLog::log(
                'update_opname_draft',
                "Draft stock opname '{$opname->nomor_opname}' was updated.",
                $updatedOpname,
                ['new' => $updatedOpname->toArray()]
            );
        }

        return $this->responseSuccess($updatedOpname, $message);
    }

    public function destroy(int $id): JsonResponse
    {
        $opname = StockOpname::find($id);

        if (! $opname) {
            return response()->json(['message' => 'Data stock opname tidak ditemukan.'], 404);
        }

        if ($opname->status === 'completed') {
            return response()->json(['message' => 'Stock opname yang sudah selesai tidak dapat dihapus.'], 422);
        }

        $nomorOpname = $opname->nomor_opname;
        $oldData = $opname->toArray();

        DB::transaction(function () use ($opname) {
            $opname->items()->delete();
            $opname->delete();
        });

        ActivityLog::log(
            'delete_opname_draft',
            "Draft stock opname '{$nomorOpname}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Draft stock opname berhasil dihapus.');
    }

    private function generateNomorOpname(): string
    {
        do {
            $number = 'OPN-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (StockOpname::where('nomor_opname', $number)->exists());

        return $number;
    }
}
