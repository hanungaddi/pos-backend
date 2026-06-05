<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query();

        // Kasir can only see active products
        if ($request->user() && $request->user()->hasRole('kasir')) {
            $query->where('status', 'active');
        }

        if ($request->filled('q')) {
            $keyword = (string) $request->string('q');

            $query->where(function ($query) use ($keyword) {
                $query->where('nama', 'like', "%{$keyword}%")
                    ->orWhere('merek', 'like', "%{$keyword}%")
                    ->orWhere('barcode', 'like', "%{$keyword}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->where('stok', '<=', 5);
        }

        $products = $query->orderBy('nama')->get();

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->all();
        $isBulk = $payload !== [] && array_is_list($payload);

        $validated = $request->validate($isBulk ? [
            '*.nama' => ['required', 'string', 'max:255'],
            '*.merek' => ['nullable', 'string', 'max:255'],
            '*.barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            '*.stok' => ['required', 'integer', 'min:0'],
            '*.harga' => ['required', 'integer', 'min:0'],
            '*.status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ] : [
            'nama' => ['required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            'stok' => ['required', 'integer', 'min:0'],
            'harga' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $products = collect($isBulk ? $validated : [$validated])
            ->map(fn (array $product) => Product::create($product))
            ->values();

        return response()->json([
            'message' => 'Produk berhasil ditambahkan',
            'data' => $isBulk ? $products : $products->first(),
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('products', 'barcode')->ignore($product->id)],
            'stok' => ['sometimes', 'required', 'integer', 'min:0'],
            'harga' => ['sometimes', 'required', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $product->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Status produk berhasil diperbarui.',
            'data' => $product
        ]);
    }

    public function showByBarcode(string $barcode, Request $request): JsonResponse
    {
        $product = Product::where('barcode', $barcode)->first();

        if (! $product) {
            return response()->json(['message' => 'Produk dengan barcode tersebut tidak ditemukan.'], 404);
        }

        // Rejection if cashier views inactive product
        if ($request->user() && $request->user()->hasRole('kasir') && $product->status !== 'active') {
            return response()->json(['message' => 'Produk tidak aktif.'], 403);
        }

        return response()->json($product);
    }
}
