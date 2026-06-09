<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ActivityLog;
use App\Services\BarcodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Eager load category and brand relations
        $query = Product::query()->with(['category', 'brand']);

        // Kasir can only see active products
        if ($request->user() && $request->user()->hasRole('kasir')) {
            $query->where('status', 'active');
        }

        // Support filtering by category_id and brand_id
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->integer('brand_id'));
        }

        // Support both 'search' and 'q'
        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;

            $query->where(function ($query) use ($keyword) {
                $query->where('nama', 'like', "%{$keyword}%")
                    ->orWhere('merek', 'like', "%{$keyword}%")
                    ->orWhere('barcode', 'like', "%{$keyword}%")
                    ->orWhereHas('category', function ($q) use ($keyword) {
                        $q->where('nama', 'like', "%{$keyword}%");
                    })
                    ->orWhereHas('brand', function ($q) use ($keyword) {
                        $q->where('nama', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($request->boolean('low_stock')) {
            $query->where('stok', '<=', 5);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'nama');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        
        $allowedSortColumns = ['nama', 'harga', 'stok', 'created_at'];
        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('nama', 'asc');
        }

        // Paginate, default to 1000
        $products = $query->paginate($request->integer('per_page', 1000));

        return $this->responsePaginated($products);
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
            '*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            '*.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
        ] : [
            'nama' => ['required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            'stok' => ['required', 'integer', 'min:0'],
            'harga' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($isBulk) {
            foreach ($validated as &$item) {
                if (empty($item['barcode'])) {
                    $item['barcode'] = Product::generateUniqueBarcode();
                }
            }
            $products = collect($validated)->map(function (array $p) {
                return Product::create($p);
            })->values();
        } else {
            if (empty($validated['barcode'])) {
                $validated['barcode'] = Product::generateUniqueBarcode();
            }
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('products', 'public');
                $validated['image_path'] = $path;
            }
            $products = collect([Product::create($validated)]);
        }

        foreach ($products as $p) {
            $p->load(['category', 'brand']);
            ActivityLog::log('create_product', "Product '{$p->nama}' was created.", $p, ['new' => $p->toArray()]);
        }

        return $this->responseSuccess(
            $isBulk ? $products : $products->first(),
            'Produk berhasil ditambahkan.',
            201
        );
    }

    public function show(Product $product): JsonResponse
    {
        return $this->responseSuccess($product->load(['category', 'brand']), 'Detail produk berhasil dimuat.');
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
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'image' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('image')) {
            // Delete old image file
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $path = $request->file('image')->store('products', 'public');
            $validated['image_path'] = $path;
        }

        $oldData = array_intersect_key($product->getOriginal(), $validated);

        $product->update($validated);

        $changes = $product->getChanges();
        if (!empty($changes)) {
            ActivityLog::log('update_product', "Product '{$product->nama}' was updated.", $product, [
                'old' => array_intersect_key($oldData, $changes),
                'new' => $changes
            ]);
        }

        return $this->responseSuccess($product->load(['category', 'brand']), 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $productName = $product->nama;
        $oldData = $product->toArray();

        // Delete associated image file
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        ActivityLog::log('delete_product', "Product '{$productName}' was deleted.", null, ['old' => $oldData]);

        return $this->responseSuccess(null, 'Produk berhasil dihapus.');
    }

    public function changeStatus(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'inactive'])],
        ]);

        $oldStatus = $product->status;

        $product->update(['status' => $validated['status']]);

        ActivityLog::log('change_product_status', "Product '{$product->nama}' status changed to '{$validated['status']}'.", $product, [
            'old' => ['status' => $oldStatus],
            'new' => ['status' => $validated['status']]
        ]);

        return $this->responseSuccess($product->load(['category', 'brand']), 'Status produk berhasil diperbarui.');
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

        return $this->responseSuccess($product->load(['category', 'brand']), 'Produk berhasil ditemukan.');
    }

    /**
     * Print standard grid of barcodes for a single product.
     */
    public function printBarcode(Request $request, int $id)
    {
        $product = Product::with('brand')->find($id);
        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        $qty = $request->integer('quantity', 30); // Default to full sheet (30 labels)

        // Force generate barcode if somehow empty
        $barcodeText = $product->barcode ?: Product::generateUniqueBarcode();
        if (!$product->barcode) {
            $product->update(['barcode' => $barcodeText]);
        }

        // Render barcode SVG using our service
        $svg = BarcodeGenerator::getSvg($barcodeText, 40, 2);
        
        $labels = [];
        for ($i = 0; $i < $qty; $i++) {
            $labels[] = [
                'nama' => $product->nama,
                'barcode' => $barcodeText,
                'svg' => $svg,
                'harga' => $product->harga,
                'brand' => $product->brand?->nama ?: $product->merek ?: 'MSG POS',
            ];
        }

        return view('barcode-print', compact('labels'));
    }

    /**
     * Print grid of barcodes for multiple products in bulk.
     */
    public function printBarcodesBulk(Request $request)
    {
        $productsInput = $request->input('products');
        if (is_string($productsInput)) {
            $productsInput = json_decode($productsInput, true);
        }

        if (empty($productsInput) || !is_array($productsInput)) {
            return response()->json(['message' => 'Input produk tidak valid.'], 422);
        }

        $labels = [];
        foreach ($productsInput as $item) {
            $productId = $item['id'] ?? null;
            $qty = $item['quantity'] ?? 1;

            if (!$productId) {
                continue;
            }

            $product = Product::with('brand')->find($productId);
            if (!$product) {
                continue;
            }

            $barcodeText = $product->barcode ?: Product::generateUniqueBarcode();
            if (!$product->barcode) {
                $product->update(['barcode' => $barcodeText]);
            }

            $svg = BarcodeGenerator::getSvg($barcodeText, 40, 2);

            for ($i = 0; $i < $qty; $i++) {
                $labels[] = [
                    'nama' => $product->nama,
                    'barcode' => $barcodeText,
                    'svg' => $svg,
                    'harga' => $product->harga,
                    'brand' => $product->brand?->nama ?: $product->merek ?: 'MSG POS',
                ];
            }
        }

        if (empty($labels)) {
            return response()->json(['message' => 'Tidak ada label barcode yang dapat dicetak.'], 422);
        }

        return view('barcode-print', compact('labels'));
    }
}
