<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $keyword = (string) $request->string('q');

                $query->where(function ($query) use ($keyword) {
                    $query->where('nama', 'like', "%{$keyword}%")
                        ->orWhere('merek', 'like', "%{$keyword}%");
                });
            })
            ->when($request->boolean('low_stock'), function ($query) {
                $query->where('stok', '<=', 5);
            })
            ->orderBy('nama')
            ->get();

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $isBulk = $payload !== [] && array_is_list($payload);

        $validated = $request->validate($isBulk ? [
            '*.nama' => ['required', 'string', 'max:255'],
            '*.merek' => ['nullable', 'string', 'max:255'],
            '*.stok' => ['required', 'integer', 'min:0'],
            '*.harga' => ['required', 'integer', 'min:0'],
        ] : [
            'nama' => ['required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'stok' => ['required', 'integer', 'min:0'],
            'harga' => ['required', 'integer', 'min:0'],
        ]);

        $products = collect($isBulk ? $validated : [$validated])
            ->map(fn (array $product) => Product::create($product))
            ->values();

        return response()->json([
            'message' => 'Produk berhasil ditambahkan',
            'data' => $isBulk ? $products : $products->first(),
        ], 201);
    }

    public function show(Product $product)
    {
        return response()->json($product);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'stok' => ['sometimes', 'required', 'integer', 'min:0'],
            'harga' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->noContent();
    }
}
