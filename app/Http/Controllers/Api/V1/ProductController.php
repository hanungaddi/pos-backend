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
use App\Exports\TemplateProduct;
use App\Imports\ProductImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Jobs\FinishProductImport;
use App\Models\ProductImportJob;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProductController extends Controller
{

    private function countRows(string $fullPath): int
    {
        $reader = IOFactory::createReaderForFile($fullPath);
        $info = $reader->listWorksheetInfo($fullPath);

        return (int) ($info[0]['totalRows'] ?? 0);
    }

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
            '*.harga' => ['required_without:*.harga_jual', 'nullable', 'integer', 'min:0'],
            '*.harga_jual' => ['required_without:*.harga', 'nullable', 'integer', 'min:0'],
            '*.harga_beli' => ['nullable', 'integer', 'min:0'],
            '*.margin' => ['nullable', 'numeric', 'min:0', 'max:100'],
            '*.status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            '*.category_id' => ['nullable', 'integer', 'exists:categories,id'],
            '*.brand_id' => ['nullable', 'integer', 'exists:brands,id'],
        ] : [
            'nama' => ['required', 'string', 'max:255'],
            'merek' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:50', 'unique:products,barcode'],
            'harga' => ['required_without:harga_jual', 'nullable', 'integer', 'min:0'],
            'harga_jual' => ['required_without:harga', 'nullable', 'integer', 'min:0'],
            'harga_beli' => ['nullable', 'integer', 'min:0'],
            'margin' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            $p->refresh();
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
            'harga' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'harga_jual' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'harga_beli' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'margin' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
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
        $keyword = strtolower($barcode);

        // Try exact barcode match first (without active filter, to preserve 403 check)
        $exactProduct = Product::with(['category', 'brand'])->where('barcode', $barcode)->first();
        
        if ($exactProduct) {
            // Rejection if cashier views inactive product
            if ($request->user() && $request->user()->hasRole('kasir') && $exactProduct->status !== 'active') {
                return response()->json(['message' => 'Produk tidak aktif.'], 403);
            }
            $products = collect([$exactProduct]);
        } else {
            // Otherwise search partial barcode or name
            $query = Product::query()->with(['category', 'brand']);

            // Kasir can only see active products in search
            if ($request->user() && $request->user()->hasRole('kasir')) {
                $query->where('status', 'active');
            }

            $products = $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(barcode) LIKE ?', ["%{$keyword}%"])
                  ->orWhereRaw('LOWER(nama) LIKE ?', ["%{$keyword}%"]);
            })
            ->limit(10)
            ->get();
        }

        if ($products->isEmpty()) {
            return response()->json(['message' => 'Produk dengan barcode tersebut tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($products, 'Produk berhasil ditemukan.');
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

    public function allPriceLogs(Request $request): JsonResponse
    {
        $query = \App\Models\ProductPriceLog::query()->with(['product', 'user']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('sumber')) {
            $query->where('sumber', $request->string('sumber'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->string('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->string('end_date'));
        }

        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->whereHas('product', function ($q) use ($keyword) {
                $q->where('nama', 'like', "%{$keyword}%");
            });
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($logs);
    }

    public function itemPriceLogs(int $id, Request $request): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        $logs = \App\Models\ProductPriceLog::where('product_id', $id)
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($logs);
    }

    public function downloadTemplate()
    {
        return Excel::download(new TemplateProduct, 'template.xlsx');
    }

    public function importTemplate(Request $request)
    {
        $request->validate([
        'file' => ['required', 'file', 'mimes:xlsx,csv'],
        ]);

        $disk = 'local';
        $file = $request->file('file');

        $path = $file->store('imports', $disk);

        $fullPath = Storage::disk($disk)->path($path);

        if (! file_exists($fullPath)) {
            return response()->json([
                'message' => 'File gagal disimpan.',
                'path' => $path,
                'full_path' => $fullPath,
            ], 500);
        }

        $totalRows = $this->countRows($fullPath);

        $importJob = ProductImportJob::create([
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => max($totalRows - 1, 0),
            'status' => 'pending',
        ]);

        (new ProductImport($importJob->id))
            ->queue($path, $disk)
            ->allOnQueue('importMasterProducts')
            ->chain([
                new FinishProductImport($importJob->id),
            ]);

        return response()->json([
            'message' => 'Import sedang diproses.',
            'import_id' => $importJob->id,
        ]);
    }

    public function progress(ProductImportJob $import)
    {
        $percent = 0;

        if ($import->status === 'completed') {
            $percent = 100;
        } elseif ($import->total_rows > 0) {
            $percent = round(($import->processed_rows / $import->total_rows) * 100, 2);
            $percent = min($percent, 100);
        }

        return response()->json([
            'id'             => $import->id,
            'status'         => $import->status,
            'total_rows'     => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'imported_rows'  => $import->imported_rows,
            'skipped_rows'   => $import->skipped_rows,
            'percent'        => $percent,
            'error_message'  => $import->error_message,
        ]);
    }
}
