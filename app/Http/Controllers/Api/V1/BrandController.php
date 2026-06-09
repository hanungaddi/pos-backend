<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Brand::query();

        // Support searching
        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where('nama', 'like', "%{$keyword}%");
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'nama');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        
        $allowedSortColumns = ['nama', 'created_at'];
        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('nama', 'asc');
        }

        // Return paginated or all
        if ($request->boolean('all')) {
            $brands = $query->get();
            return $this->responseSuccess($brands, 'Daftar merek berhasil dimuat.');
        }

        $brands = $query->paginate($request->integer('per_page', 15));
        return $this->responsePaginated($brands);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'unique:brands,nama'],
        ]);

        $brand = Brand::create([
            'nama' => $validated['nama'],
            'store_id' => $request->user()?->store_id,
        ]);

        ActivityLog::log(
            'create_brand',
            "Brand '{$brand->nama}' was created.",
            $brand,
            ['new' => $brand->toArray()]
        );

        return $this->responseSuccess($brand, 'Merek berhasil ditambahkan.', 201);
    }

    public function show(Brand $brand): JsonResponse
    {
        return $this->responseSuccess($brand, 'Detail merek berhasil dimuat.');
    }

    public function update(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('brands', 'nama')->ignore($brand->id)],
        ]);

        $oldData = $brand->toArray();
        $brand->update(['nama' => $validated['nama']]);

        ActivityLog::log(
            'update_brand',
            "Brand '{$brand->nama}' was updated.",
            $brand,
            ['old' => $oldData, 'new' => $brand->toArray()]
        );

        return $this->responseSuccess($brand, 'Merek berhasil diperbarui.');
    }

    public function destroy(Brand $brand): JsonResponse
    {
        $brandName = $brand->nama;
        $oldData = $brand->toArray();

        $brand->delete();

        ActivityLog::log(
            'delete_brand',
            "Brand '{$brandName}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Merek berhasil dihapus.');
    }
}
