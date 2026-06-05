<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $sales = $this->applySaleFilters(Sale::query(), $request)
            ->with('items')
            ->latest()
            ->get();

        return response()->json($sales);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'cashier_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'tax' => ['nullable', 'integer', 'min:0'],
            'paid' => ['required', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $sale = DB::transaction(function () use ($validated) {
            $items = collect($validated['items'])
                ->groupBy('product_id')
                ->map(fn ($items, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => (int) $items->sum('quantity'),
                ])
                ->values();

            $products = Product::whereIn('id', $items->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $saleItems = [];

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => ['Produk tidak ditemukan.'],
                    ]);
                }

                if ($product->stok < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ["Stok {$product->nama} tidak mencukupi. Sisa stok: {$product->stok}."],
                    ]);
                }

                $lineSubtotal = $product->harga * $item['quantity'];
                $subtotal += $lineSubtotal;

                $saleItems[] = [
                    'product' => $product,
                    'data' => [
                        'product_id' => $product->id,
                        'product_name' => $product->nama,
                        'price' => $product->harga,
                        'quantity' => $item['quantity'],
                        'subtotal' => $lineSubtotal,
                    ],
                ];
            }

            $discount = (int) ($validated['discount'] ?? 0);
            $tax = (int) ($validated['tax'] ?? 0);

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount' => ['Diskon tidak boleh lebih besar dari subtotal.'],
                ]);
            }

            $total = $subtotal - $discount + $tax;
            $paid = (int) $validated['paid'];

            if ($paid < $total) {
                throw ValidationException::withMessages([
                    'paid' => ['Nominal bayar kurang dari total transaksi.'],
                ]);
            }

            $sale = Sale::create([
                'invoice_number' => $this->generateInvoiceNumber(),
                'customer_name' => $validated['customer_name'] ?? null,
                'cashier_name' => $validated['cashier_name'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid' => $paid,
                'change_amount' => $paid - $total,
                'status' => 'completed',
            ]);

            foreach ($saleItems as $item) {
                $sale->items()->create($item['data']);
                $item['product']->decrement('stok', $item['data']['quantity']);
            }

            return $sale->load('items');
        });

        return response()->json([
            'message' => 'Transaksi berhasil dibuat',
            'data' => $sale,
        ], 201);
    }

    public function show(Sale $sale)
    {
        return response()->json($sale->load('items.product'));
    }

    public function summary(Request $request)
    {
        $salesQuery = $this->applySaleFilters(Sale::query(), $request);
        $itemsQuery = SaleItem::query()
            ->whereHas('sale', function (Builder $query) use ($request) {
                $this->applySaleFilters($query, $request);
            });

        $topProducts = (clone $itemsQuery)
            ->select('product_name')
            ->selectRaw('SUM(quantity) as quantity')
            ->selectRaw('SUM(subtotal) as revenue')
            ->groupBy('product_name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn (SaleItem $item) => [
                'product_name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'revenue' => (int) $item->revenue,
            ]);

        return response()->json([
            'sales_count' => (int) (clone $salesQuery)->count(),
            'items_sold' => (int) (clone $itemsQuery)->sum('quantity'),
            'gross_sales' => (int) (clone $salesQuery)->sum('subtotal'),
            'discount_total' => (int) (clone $salesQuery)->sum('discount'),
            'tax_total' => (int) (clone $salesQuery)->sum('tax'),
            'net_sales' => (int) (clone $salesQuery)->sum('total'),
            'top_products' => $topProducts,
        ]);
    }

    private function applySaleFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('from'), function (Builder $query) use ($request) {
                $query->whereDate('created_at', '>=', $request->date('from')->toDateString());
            })
            ->when($request->filled('to'), function (Builder $query) use ($request) {
                $query->whereDate('created_at', '<=', $request->date('to')->toDateString());
            })
            ->when($request->filled('payment_method'), function (Builder $query) use ($request) {
                $query->where('payment_method', (string) $request->string('payment_method'));
            });
    }

    private function generateInvoiceNumber(): string
    {
        do {
            $invoiceNumber = 'TRX-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        } while (Sale::where('invoice_number', $invoiceNumber)->exists());

        return $invoiceNumber;
    }
}
