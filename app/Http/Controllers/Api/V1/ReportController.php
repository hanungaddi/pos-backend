<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $salesQuery = Transaction::where('status', 'completed');
        $this->applyFilters($salesQuery, $request);

        $itemsQuery = TransactionItem::whereHas('transaction', function (Builder $query) use ($request) {
            $query->where('status', 'completed');
            $this->applyFilters($query, $request);
        });

        $topProducts = (clone $itemsQuery)
            ->select('nama_produk')
            ->selectRaw('SUM(kuantitas) as quantity')
            ->selectRaw('SUM(subtotal) as revenue')
            ->groupBy('nama_produk')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn (TransactionItem $item) => [
                'product_name' => $item->nama_produk,
                'quantity' => (int) $item->quantity,
                'revenue' => (int) $item->revenue,
            ]);

        return response()->json([
            'sales_count' => (int) (clone $salesQuery)->count(),
            'items_sold' => (int) (clone $itemsQuery)->sum('kuantitas'),
            'gross_sales' => (int) (clone $salesQuery)->sum('subtotal'),
            'discount_total' => (int) (clone $salesQuery)->sum('diskon'),
            'tax_total' => (int) (clone $salesQuery)->sum('pajak'),
            'net_sales' => (int) (clone $salesQuery)->sum('total'),
            'top_products' => $topProducts,
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        $date = $request->date('date') ?? now();
        $dateString = $date->toDateString();

        // Base query for today's completed transactions
        $salesQuery = Transaction::where('status', 'completed')
            ->whereDate('created_at', $dateString);

        $totalSales = (int) (clone $salesQuery)->sum('total');
        $txCount = (int) (clone $salesQuery)->count();
        $avgValue = $txCount > 0 ? (int) round($totalSales / $txCount) : 0;

        // Payment method breakdown
        $paymentMethods = [
            'cash' => ['total' => 0, 'count' => 0],
            'card' => ['total' => 0, 'count' => 0],
            'split' => ['total' => 0, 'count' => 0],
        ];

        $paymentBreakdown = (clone $salesQuery)
            ->select('metode_pembayaran')
            ->selectRaw('SUM(total) as total')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('metode_pembayaran')
            ->get();

        foreach ($paymentBreakdown as $breakdown) {
            $method = $breakdown->metode_pembayaran;
            if (array_key_exists($method, $paymentMethods)) {
                $paymentMethods[$method] = [
                    'total' => (int) $breakdown->total,
                    'count' => (int) $breakdown->count,
                ];
            }
        }

        // Top 10 products
        $itemsQuery = TransactionItem::whereHas('transaction', function (Builder $query) use ($dateString) {
            $query->where('status', 'completed')->whereDate('created_at', $dateString);
        });

        $topProducts = (clone $itemsQuery)
            ->select('nama_produk')
            ->selectRaw('SUM(kuantitas) as quantity')
            ->selectRaw('SUM(subtotal) as revenue')
            ->groupBy('nama_produk')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get()
            ->map(fn (TransactionItem $item) => [
                'product_name' => $item->nama_produk,
                'quantity' => (int) $item->quantity,
                'revenue' => (int) $item->revenue,
            ]);

        // Void count
        $voidCount = (int) Transaction::where('status', 'void')
            ->whereDate('created_at', $dateString)
            ->count();

        return response()->json([
            'date' => $dateString,
            'total_sales' => $totalSales,
            'transactions_count' => $txCount,
            'average_transaction_value' => $avgValue,
            'payment_methods' => $paymentMethods,
            'top_products' => $topProducts,
            'void_count' => $voidCount,
        ]);
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('from'), function (Builder $q) use ($request) {
                $q->whereDate('created_at', '>=', $request->date('from')->toDateString());
            })
            ->when($request->filled('to'), function (Builder $q) use ($request) {
                $q->whereDate('created_at', '<=', $request->date('to')->toDateString());
            })
            ->when($request->filled('payment_method'), function (Builder $q) use ($request) {
                $q->where('metode_pembayaran', $request->string('payment_method'));
            });
    }
}
