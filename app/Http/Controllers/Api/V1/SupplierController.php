<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('nomor_telepon', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'nama');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['nama', 'email', 'created_at'];

        if (in_array($sortBy, $allowedSort)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('nama', 'asc');
        }

        $suppliers = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($suppliers);
    }

    public function all(): JsonResponse
    {
        $suppliers = Supplier::orderBy('nama', 'asc')->get();
        return $this->responseSuccess($suppliers, 'List all suppliers fetched.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'nomor_telepon' => 'nullable|string|max:50',
            'alamat' => 'nullable|string',
        ]);

        $supplier = Supplier::create($validated);

        ActivityLog::log(
            'create_supplier',
            "Supplier '{$supplier->nama}' was created.",
            $supplier,
            ['new' => $supplier->toArray()]
        );

        return $this->responseSuccess($supplier, 'Supplier berhasil ditambahkan.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $supplier = Supplier::find($id);

        if (! $supplier) {
            return response()->json(['message' => 'Supplier tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($supplier, 'Detail supplier fetched.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::find($id);

        if (! $supplier) {
            return response()->json(['message' => 'Supplier tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'nama' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'nomor_telepon' => 'nullable|string|max:50',
            'alamat' => 'nullable|string',
        ]);

        $oldData = array_intersect_key($supplier->getOriginal(), $validated);
        
        $supplier->update($validated);

        $changes = $supplier->getChanges();

        if (!empty($changes)) {
            $properties = [
                'old' => array_intersect_key($oldData, $changes),
                'new' => $changes,
            ];

            ActivityLog::log(
                'update_supplier',
                "Supplier '{$supplier->nama}' was updated.",
                $supplier,
                $properties
            );
        }

        return $this->responseSuccess($supplier, 'Supplier berhasil diperbarui.');
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::find($id);

        if (! $supplier) {
            return response()->json(['message' => 'Supplier tidak ditemukan.'], 404);
        }

        $supplierName = $supplier->nama;
        $oldData = $supplier->toArray();

        $supplier->delete();

        ActivityLog::log(
            'delete_supplier',
            "Supplier '{$supplierName}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Supplier berhasil dihapus.');
    }
}
