<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashDrawerMovement;
use App\Models\CashDrawerSession;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Transaction::query()->with(['items.product', 'user', 'voidBy']);

        // Kasir can only view their own transactions
        if ($user->hasRole('kasir')) {
            $query->where('user_id', $user->id);
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to')->toDateString());
        }

        // Support search (standardized) and q (fallback)
        $search = $request->input('search') ?? $request->input('q');
        if (!empty($search)) {
            $keyword = (string) $search;
            $query->where('nomor_transaksi', 'like', "%{$keyword}%");
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        
        $allowedSortColumns = ['nomor_transaksi', 'total', 'created_at', 'status'];
        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest();
        }

        $transactions = $query->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($transactions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nomor_transaksi' => ['nullable', 'string', 'max:30', 'unique:transactions,nomor_transaksi'],
            'is_offline' => ['nullable', 'boolean'],
            'offline_id' => ['nullable', 'uuid'],
            'created_at' => ['nullable', 'date'],
            'diskon' => ['nullable', 'integer', 'min:0'],
            'pajak' => ['nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required_without:items.*.barcode', 'nullable', 'integer', 'exists:products,id'],
            'items.*.barcode' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            
            // Payment fields
            'metode_pembayaran' => ['required', 'string', 'in:cash,card,split'],
            
            // Cash payment validation
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'nominal_bayar' => ['nullable', 'numeric', 'min:0'],
            
            // Card payment validation
            'jenis_kartu' => ['nullable', 'string', 'in:debit,kredit,credit'],
            'card_type' => ['nullable', 'string', 'in:debit,kredit,credit'],
            'nomor_kartu_akhir' => ['nullable', 'string', 'size:4'],
            'last_four' => ['nullable', 'string', 'size:4'],
            'referensi_edc' => ['nullable', 'string', 'max:50'],
            'reference_number' => ['nullable', 'string', 'max:50'],
            
            // Split payment validation
            'cash_amount' => ['nullable', 'integer', 'min:0'],
            'card_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $updatedTransaction = DB::transaction(function () use ($validated, $request) {
            $nomorTransaksi = $validated['nomor_transaksi'] ?? $this->generateTransactionNumber();
            
            $transaction = Transaction::create([
                'store_id' => $request->user()->store_id,
                'user_id' => $request->user()->id,
                'nomor_transaksi' => $nomorTransaksi,
                'status' => 'draft',
                'diskon' => $validated['diskon'] ?? 0,
                'pajak' => $validated['pajak'] ?? 0,
                'is_offline' => $validated['is_offline'] ?? false,
                'offline_id' => $validated['offline_id'] ?? null,
                'created_at' => $validated['created_at'] ?? now(),
            ]);

            // Add items
            foreach ($validated['items'] as $itemData) {
                $product = null;
                if (!empty($itemData['product_id'])) {
                    $product = Product::find($itemData['product_id']);
                } elseif (!empty($itemData['barcode'])) {
                    $product = Product::where('barcode', $itemData['barcode'])->first();
                }

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ['Produk tidak ditemukan.'],
                    ]);
                }

                if ($product->status !== 'active') {
                    throw ValidationException::withMessages([
                        'items' => ["Produk {$product->nama} tidak aktif."],
                    ]);
                }

                $subtotal = $product->harga * $itemData['quantity'];

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'nama_produk' => $product->nama,
                    'barcode' => $product->barcode,
                    'harga_satuan' => $product->harga,
                    'kuantitas' => $itemData['quantity'],
                    'subtotal' => $subtotal,
                    'is_taxable' => true,
                    'diskon_item' => 0,
                ]);
            }

            // Calculate totals
            $this->recalculateTotals($transaction, $validated['pajak'] ?? null, $validated['diskon'] ?? null);
            $transaction->refresh(); // reload calculated fields

            $total = $transaction->total;
            $metode = $validated['metode_pembayaran'];

            $paymentDetails = [];

            if ($metode === 'cash') {
                $cashReceived = $validated['cash_received'] ?? $validated['nominal_bayar'] ?? null;
                if ($cashReceived === null) {
                    throw ValidationException::withMessages([
                        'cash_received' => ['Nominal bayar wajib diisi.'],
                    ]);
                }
                $cashReceived = (int) $cashReceived;
                if ($cashReceived < $total) {
                    throw ValidationException::withMessages([
                        'cash_received' => ['Nominal bayar kurang dari total transaksi.'],
                    ]);
                }

                $this->validateAndDeductStock($transaction, $request->user());
                $cashDrawerSessionId = $this->recordCashDrawerSale($transaction, $request->user(), $total);

                $paymentDetails = [
                    'status' => 'completed',
                    'metode_pembayaran' => 'cash',
                    'nominal_bayar' => $cashReceived,
                    'kembalian' => $cashReceived - $total,
                    'cash_drawer_session_id' => $cashDrawerSessionId,
                ];
                
                $logAction = 'checkout_cash';
                $logMessage = "Transaction #{$transaction->id} was paid using Cash.";
            } elseif ($metode === 'card') {
                $jenisKartu = $validated['jenis_kartu'] ?? $validated['card_type'] ?? 'debit';
                $nomorKartuAkhir = $validated['nomor_kartu_akhir'] ?? $validated['last_four'] ?? '0000';
                $referensiEdc = $validated['referensi_edc'] ?? $validated['reference_number'] ?? ('EDC-' . now()->timestamp);

                $jenisKartu = $jenisKartu === 'credit' ? 'kredit' : $jenisKartu;

                $this->validateAndDeductStock($transaction, $request->user());

                $paymentDetails = [
                    'status' => 'completed',
                    'metode_pembayaran' => 'card',
                    'nominal_bayar' => $total,
                    'kembalian' => 0,
                    'jenis_kartu' => $jenisKartu,
                    'nomor_kartu_akhir' => $nomorKartuAkhir,
                    'referensi_edc' => $referensiEdc,
                ];

                $logAction = 'checkout_card';
                $logMessage = "Transaction #{$transaction->id} was paid using Card.";
            } elseif ($metode === 'split') {
                $cashAmount = $validated['cash_amount'] ?? null;
                $cardAmount = $validated['card_amount'] ?? null;
                if ($cashAmount === null || $cardAmount === null) {
                    throw ValidationException::withMessages([
                        'cash_amount' => ['Split payment requires both cash_amount and card_amount.'],
                    ]);
                }

                if (($cashAmount + $cardAmount) < $total) {
                    throw ValidationException::withMessages([
                        'cash_amount' => ['Jumlah kombinasi split kurang dari total transaksi.'],
                    ]);
                }

                $nominalBayar = $validated['nominal_bayar'] ?? $validated['cash_received'] ?? null;
                if ($nominalBayar === null) {
                    throw ValidationException::withMessages([
                        'nominal_bayar' => ['Nominal bayar tunai wajib diisi.'],
                    ]);
                }

                if ($nominalBayar < $cashAmount) {
                    throw ValidationException::withMessages([
                        'nominal_bayar' => ['Nominal bayar tunai kurang dari porsi split tunai.'],
                    ]);
                }

                $jenisKartu = $validated['jenis_kartu'] ?? $validated['card_type'] ?? 'debit';
                $nomorKartuAkhir = $validated['nomor_kartu_akhir'] ?? $validated['last_four'] ?? '0000';
                $referensiEdc = $validated['referensi_edc'] ?? $validated['reference_number'] ?? ('EDC-' . now()->timestamp);
                $jenisKartu = $jenisKartu === 'credit' ? 'kredit' : $jenisKartu;

                $this->validateAndDeductStock($transaction, $request->user());
                $cashDrawerSessionId = $this->recordCashDrawerSale($transaction, $request->user(), (int) $cashAmount);

                $paymentDetails = [
                    'status' => 'completed',
                    'metode_pembayaran' => 'split',
                    'nominal_bayar' => $nominalBayar + $cardAmount,
                    'kembalian' => $nominalBayar - $cashAmount,
                    'jenis_kartu' => $jenisKartu,
                    'nomor_kartu_akhir' => $nomorKartuAkhir,
                    'referensi_edc' => $referensiEdc,
                    'cash_drawer_session_id' => $cashDrawerSessionId,
                ];

                $logAction = 'checkout_split';
                $logMessage = "Transaction #{$transaction->id} was paid using Split payment.";
            }

            $transaction->update($paymentDetails);

            $finalTrx = $transaction->fresh(['items.product', 'user']);
            \App\Models\ActivityLog::log($logAction, $logMessage, $finalTrx, ['total' => $finalTrx->total]);

            return $finalTrx;
        });

        return $this->responseSuccess($updatedTransaction, 'Transaksi berhasil dibayar.', 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user() ?? auth()->user();
        $query = Transaction::with(['items.product', 'user', 'voidBy']);

        if ($user && $user->hasRole('kasir')) {
            $query->where('user_id', $user->id);
        }

        $transaction = $query->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($transaction, 'Detail transaksi berhasil dimuat.');
    }

    private function validateAndDeductStock(Transaction $transaction, $user): void
    {
        if ($transaction->items()->count() === 0) {
            throw ValidationException::withMessages([
                'items' => ['Transaksi tidak memiliki item.'],
            ]);
        }

        foreach ($transaction->items as $item) {
            $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
            
            if (!$product) {
                throw ValidationException::withMessages([
                    'items' => ["Produk {$item->nama_produk} tidak ditemukan."],
                ]);
            }

            if ($product->stok < $item->kuantitas) {
                throw ValidationException::withMessages([
                    'items' => ["Stok {$product->nama} tidak mencukupi. Sisa stok: {$product->stok}."],
                ]);
            }

            $stokSebelum = $product->stok;
            $stokSesudah = $stokSebelum - $item->kuantitas;

            $product->decrement('stok', $item->kuantitas);

            StockMovement::create([
                'store_id' => $user->store_id,
                'product_id' => $product->id,
                'tipe' => 'sale',
                'kuantitas' => -$item->kuantitas,
                'stok_sebelum' => $stokSebelum,
                'stok_sesudah' => $stokSesudah,
                'referensi_id' => $transaction->id,
                'referensi_tipe' => 'transaction',
                'alasan' => 'Transaksi penjualan ' . $transaction->nomor_transaksi,
                'user_id' => $user->id,
            ]);
        }
    }

    private function recordCashDrawerSale(Transaction $transaction, $user, int $cashAmount): ?int
    {
        if ($cashAmount <= 0) {
            return null;
        }

        $session = CashDrawerSession::query()
            ->open()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (!$session) {
            return null;
        }

        $balanceBefore = $session->expected_cash;
        $balanceAfter = $balanceBefore + $cashAmount;

        $session->update([
            'expected_cash' => $balanceAfter,
            'cash_sales_total' => $session->cash_sales_total + $cashAmount,
        ]);

        $movement = $session->recordMovement(
            'cash_sale',
            $cashAmount,
            $balanceBefore,
            $balanceAfter,
            $user,
            'Pembayaran tunai transaksi ' . $transaction->nomor_transaksi,
            $transaction->id,
            'transaction'
        );

        \App\Models\ActivityLog::log(
            'cash_drawer_cash_sale',
            "Cash drawer #{$session->id} recorded cash sale for transaction {$transaction->nomor_transaksi}.",
            $session,
            [
                'movement_id' => $movement->id,
                'transaction_id' => $transaction->id,
                'transaction_number' => $transaction->nomor_transaksi,
                'amount' => $cashAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]
        );

        return $session->id;
    }



    private function recalculateTotals(Transaction $transaction, ?int $customTax = null, ?int $customDiscount = null): void
    {
        $subtotal = 0;
        foreach ($transaction->items as $item) {
            $subtotal += $item->subtotal;
        }

        $discount = $customDiscount !== null ? $customDiscount : $transaction->diskon;
        $tax = $customTax !== null ? $customTax : $transaction->pajak;

        $transaction->update([
            'subtotal' => $subtotal,
            'diskon' => $discount,
            'pajak' => $tax,
            'total' => max(0, $subtotal - $discount + $tax),
        ]);
    }

    private function generateTransactionNumber(): string
    {
        do {
            $number = 'TRX-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5));
        } while (Transaction::where('nomor_transaksi', $number)->exists());

        return $number;
    }
}
