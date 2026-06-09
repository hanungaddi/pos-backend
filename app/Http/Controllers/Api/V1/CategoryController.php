<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

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
            $categories = $query->get();
            return $this->responseSuccess($categories, 'Daftar kategori berhasil dimuat.');
        }

        $categories = $query->paginate($request->integer('per_page', 15));
        return $this->responsePaginated($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'unique:categories,nama'],
        ]);

        $category = Category::create([
            'nama' => $validated['nama'],
            'store_id' => $request->user()?->store_id,
        ]);

        ActivityLog::log(
            'create_category',
            "Category '{$category->nama}' was created.",
            $category,
            ['new' => $category->toArray()]
        );

        return $this->responseSuccess($category, 'Kategori berhasil ditambahkan.', 201);
    }

    public function show(Category $category): JsonResponse
    {
        return $this->responseSuccess($category, 'Detail kategori berhasil dimuat.');
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', Rule::unique('categories', 'nama')->ignore($category->id)],
        ]);

        $oldData = $category->toArray();
        $category->update(['nama' => $validated['nama']]);

        ActivityLog::log(
            'update_category',
            "Category '{$category->nama}' was updated.",
            $category,
            ['old' => $oldData, 'new' => $category->toArray()]
        );

        return $this->responseSuccess($category, 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $categoryName = $category->nama;
        $oldData = $category->toArray();

        $category->delete();

        ActivityLog::log(
            'delete_category',
            "Category '{$categoryName}' was deleted.",
            null,
            ['old' => $oldData]
        );

        return $this->responseSuccess(null, 'Kategori berhasil dihapus.');
    }
}
