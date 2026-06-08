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
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_without:items.*.barcode', 'nullable', 'integer', 'exists:products,id'],
            'items.*.barcode' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $transaction = DB::transaction(function () use ($validated, $request) {
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

            if (!empty($validated['items'])) {
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
            }

            $this->recalculateTotals($transaction, $validated['pajak'] ?? null, $validated['diskon'] ?? null);

            return $transaction->load(['items.product', 'user']);
        });

        return $this->responseSuccess($transaction, 'Transaksi berhasil dibuat.', 201);
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

    public function addItem(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::where('status', 'draft')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi draft tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'product_id' => ['required_without:barcode', 'nullable', 'integer', 'exists:products,id'],
            'barcode' => ['nullable', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = null;
        if (!empty($validated['product_id'])) {
            $product = Product::find($validated['product_id']);
        } elseif (!empty($validated['barcode'])) {
            $product = Product::where('barcode', $validated['barcode'])->first();
        }

        if (!$product) {
            return response()->json(['message' => 'Produk tidak ditemukan.'], 404);
        }

        if ($product->status !== 'active') {
            return response()->json(['message' => 'Produk tidak aktif.'], 400);
        }

        DB::transaction(function () use ($transaction, $product, $validated) {
            // Check if item already exists in transaction
            $existingItem = $transaction->items()->where('product_id', $product->id)->first();

            if ($existingItem) {
                $newQty = $existingItem->kuantitas + $validated['quantity'];
                $existingItem->update([
                    'kuantitas' => $newQty,
                    'subtotal' => $existingItem->harga_satuan * $newQty,
                ]);
            } else {
                $transaction->items()->create([
                    'product_id' => $product->id,
                    'nama_produk' => $product->nama,
                    'barcode' => $product->barcode,
                    'harga_satuan' => $product->harga,
                    'kuantitas' => $validated['quantity'],
                    'subtotal' => $product->harga * $validated['quantity'],
                    'is_taxable' => true,
                    'diskon_item' => 0,
                ]);
            }

            $this->recalculateTotals($transaction);
        });

        return $this->responseSuccess($transaction->load('items.product'), 'Item berhasil ditambahkan.');
    }

    public function updateItem(Request $request, $id, $itemId): JsonResponse
    {
        $transaction = Transaction::where('status', 'draft')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi draft tidak ditemukan.'], 404);
        }

        $item = $transaction->items()->find($itemId);
        if (!$item) {
            return response()->json(['message' => 'Item transaksi tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($transaction, $item, $validated) {
            $item->update([
                'kuantitas' => $validated['quantity'],
                'subtotal' => $item->harga_satuan * $validated['quantity'],
            ]);

            $this->recalculateTotals($transaction);
        });

        return $this->responseSuccess($transaction->load('items.product'), 'Item berhasil diperbarui.');
    }

    public function removeItem($id, $itemId): JsonResponse
    {
        $transaction = Transaction::where('status', 'draft')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi draft tidak ditemukan.'], 404);
        }

        $item = $transaction->items()->find($itemId);
        if (!$item) {
            return response()->json(['message' => 'Item transaksi tidak ditemukan.'], 404);
        }

        DB::transaction(function () use ($transaction, $item) {
            $item->delete();
            $this->recalculateTotals($transaction);
        });

        return $this->responseSuccess($transaction->load('items.product'), 'Item berhasil dihapus.');
    }

    public function hold($id): JsonResponse
    {
        $transaction = Transaction::where('status', 'draft')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi draft tidak ditemukan.'], 404);
        }

        if ($transaction->items()->count() === 0) {
            return response()->json(['message' => 'Transaksi kosong tidak dapat ditunda.'], 400);
        }

        $transaction->update(['status' => 'hold']);

        \App\Models\ActivityLog::log('hold_transaction', "Transaction #{$transaction->id} was put on hold.", $transaction);

        return $this->responseSuccess($transaction, 'Transaksi berhasil ditunda.');
    }

    public function recall($id): JsonResponse
    {
        $transaction = Transaction::where('status', 'hold')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi hold tidak ditemukan.'], 404);
        }

        $transaction->update(['status' => 'draft']);

        \App\Models\ActivityLog::log('recall_transaction', "Transaction #{$transaction->id} was recalled.", $transaction);

        return $this->responseSuccess($transaction->load('items.product'), 'Transaksi berhasil dipanggil kembali.');
    }

    public function listOnHold(Request $request): JsonResponse
    {
        $transactions = Transaction::where('status', 'hold')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return $this->responseSuccess($transactions, 'Daftar transaksi tunda berhasil dimuat.');
    }

    public function payCash(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::whereIn('status', ['draft', 'hold'])->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi aktif tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'cash_received'  => ['nullable', 'numeric', 'min:0'],
            'nominal_bayar'  => ['nullable', 'numeric', 'min:0'],
            'diskon'         => ['nullable', 'integer', 'min:0'],
            'pajak'          => ['nullable', 'integer', 'min:0'],
        ]);

        $cashReceived = $validated['cash_received'] ?? $validated['nominal_bayar'] ?? null;

        if ($cashReceived === null) {
            throw ValidationException::withMessages([
                'cash_received' => ['Nominal bayar wajib diisi.'],
            ]);
        }

        $cashReceived = (int) $cashReceived;

        $this->recalculateTotals($transaction, $validated['pajak'] ?? null, $validated['diskon'] ?? null);

        if ($cashReceived < $transaction->total) {
            throw ValidationException::withMessages([
                'cash_received' => ['Nominal bayar kurang dari total transaksi.'],
            ]);
        }

        $updatedTransaction = DB::transaction(function () use ($transaction, $cashReceived, $request) {
            $this->validateAndDeductStock($transaction, $request->user());
            $cashDrawerSessionId = $this->recordCashDrawerSale($transaction, $request->user(), $transaction->total);

            $transaction->update([
                'status'           => 'completed',
                'metode_pembayaran'=> 'cash',
                'nominal_bayar'    => $cashReceived,
                'kembalian'        => $cashReceived - $transaction->total,
                'cash_drawer_session_id' => $cashDrawerSessionId,
            ]);

            return $transaction->fresh(['items.product', 'user']);
        });

        \App\Models\ActivityLog::log('checkout_cash', "Transaction #{$transaction->id} was paid using Cash.", $updatedTransaction, ['total' => $updatedTransaction->total]);

        return $this->responseSuccess($updatedTransaction, 'Transaksi berhasil dibayar.');
    }

    public function payCard(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::whereIn('status', ['draft', 'hold'])->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi aktif tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'jenis_kartu'        => ['nullable', 'string', 'in:debit,kredit,credit'],
            'card_type'          => ['nullable', 'string', 'in:debit,kredit,credit'],
            'nomor_kartu_akhir'  => ['nullable', 'string', 'size:4'],
            'last_four'          => ['nullable', 'string', 'size:4'],
            'referensi_edc'      => ['nullable', 'string', 'max:50'],
            'reference_number'   => ['nullable', 'string', 'max:50'],
            'diskon'             => ['nullable', 'integer', 'min:0'],
            'pajak'              => ['nullable', 'integer', 'min:0'],
        ]);

        $jenisKartu       = $validated['jenis_kartu'] ?? $validated['card_type'] ?? 'debit';
        $nomorKartuAkhir  = $validated['nomor_kartu_akhir'] ?? $validated['last_four'] ?? '0000';
        $referensiEdc     = $validated['referensi_edc'] ?? $validated['reference_number'] ?? ('EDC-' . now()->timestamp);

        $jenisKartu = $jenisKartu === 'credit' ? 'kredit' : $jenisKartu;

        $this->recalculateTotals($transaction, $validated['pajak'] ?? null, $validated['diskon'] ?? null);

        $updatedTransaction = DB::transaction(function () use ($transaction, $jenisKartu, $nomorKartuAkhir, $referensiEdc, $request) {
            $this->validateAndDeductStock($transaction, $request->user());

            $transaction->update([
                'status'            => 'completed',
                'metode_pembayaran' => 'card',
                'nominal_bayar'     => $transaction->total,
                'kembalian'         => 0,
                'jenis_kartu'       => $jenisKartu,
                'nomor_kartu_akhir' => $nomorKartuAkhir,
                'referensi_edc'     => $referensiEdc,
            ]);

            return $transaction->fresh(['items.product', 'user']);
        });

        \App\Models\ActivityLog::log('checkout_card', "Transaction #{$transaction->id} was paid using Card.", $updatedTransaction, ['total' => $updatedTransaction->total]);

        return $this->responseSuccess($updatedTransaction, 'Transaksi berhasil dibayar via kartu.');
    }

    public function paySplit(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::whereIn('status', ['draft', 'hold'])->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi aktif tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'cash_amount' => ['required', 'integer', 'min:0'],
            'card_amount' => ['required', 'integer', 'min:0'],
            'nominal_bayar' => ['required', 'integer', 'min:0'],
            'jenis_kartu' => ['required', 'string', 'in:debit,kredit'],
            'nomor_kartu_akhir' => ['required', 'string', 'size:4'],
            'referensi_edc' => ['required', 'string', 'max:50'],
            'diskon' => ['nullable', 'integer', 'min:0'],
            'pajak' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->recalculateTotals($transaction, $validated['pajak'] ?? null, $validated['diskon'] ?? null);

        if (($validated['cash_amount'] + $validated['card_amount']) < $transaction->total) {
            throw ValidationException::withMessages([
                'cash_amount' => ['Jumlah kombinasi split kurang dari total transaksi.'],
            ]);
        }

        if ($validated['nominal_bayar'] < $validated['cash_amount']) {
            throw ValidationException::withMessages([
                'nominal_bayar' => ['Nominal bayar tunai kurang dari porsi split tunai.'],
            ]);
        }

        $updatedTransaction = DB::transaction(function () use ($transaction, $validated, $request) {
            $this->validateAndDeductStock($transaction, $request->user());
            $cashDrawerSessionId = $this->recordCashDrawerSale($transaction, $request->user(), (int) $validated['cash_amount']);

            $transaction->update([
                'status' => 'completed',
                'metode_pembayaran' => 'split',
                'nominal_bayar' => $validated['nominal_bayar'] + $validated['card_amount'],
                'kembalian' => $validated['nominal_bayar'] - $validated['cash_amount'],
                'jenis_kartu' => $validated['jenis_kartu'],
                'nomor_kartu_akhir' => $validated['nomor_kartu_akhir'],
                'referensi_edc' => $validated['referensi_edc'],
                'cash_drawer_session_id' => $cashDrawerSessionId,
            ]);

            return $transaction->fresh(['items.product', 'user']);
        });

        \App\Models\ActivityLog::log('checkout_split', "Transaction #{$transaction->id} was paid using Split payment.", $updatedTransaction, ['total' => $updatedTransaction->total]);

        return $this->responseSuccess($updatedTransaction, 'Transaksi split berhasil dibayar.');
    }

    public function void(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::where('status', 'completed')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi completed tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'catatan_void' => ['required', 'string', 'max:255'],
        ]);

        $voidedTransaction = DB::transaction(function () use ($transaction, $validated, $request) {
            foreach ($transaction->items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $stokSebelum = $product->stok;
                    $stokSesudah = $stokSebelum + $item->kuantitas;

                    $product->increment('stok', $item->kuantitas);

                    StockMovement::create([
                        'store_id' => $request->user()->store_id,
                        'product_id' => $product->id,
                        'tipe' => 'void',
                        'kuantitas' => $item->kuantitas,
                        'stok_sebelum' => $stokSebelum,
                        'stok_sesudah' => $stokSesudah,
                        'referensi_id' => $transaction->id,
                        'referensi_tipe' => 'transaction',
                        'alasan' => 'Void transaksi: ' . $validated['catatan_void'],
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            $transaction->update([
                'status' => 'void',
                'catatan_void' => $validated['catatan_void'],
                'void_by' => $request->user()->id,
                'voided_at' => now(),
            ]);

            $this->recordCashDrawerRefund($transaction, $request->user());

            return $transaction->fresh(['items.product', 'user', 'voidBy']);
        });

        \App\Models\ActivityLog::log('void_transaction', "Transaction #{$transaction->id} was voided. Reason: {$validated['catatan_void']}", $voidedTransaction);

        return $this->responseSuccess($voidedTransaction, 'Transaksi berhasil di-void.');
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

    private function recordCashDrawerRefund(Transaction $transaction, $user): void
    {
        if (!$transaction->cash_drawer_session_id || !in_array($transaction->metode_pembayaran, ['cash', 'split'])) {
            return;
        }

        $saleMovement = CashDrawerMovement::query()
            ->where('cash_drawer_session_id', $transaction->cash_drawer_session_id)
            ->where('reference_type', 'transaction')
            ->where('reference_id', $transaction->id)
            ->where('type', 'cash_sale')
            ->first();

        if (!$saleMovement) {
            return;
        }

        $session = CashDrawerSession::query()
            ->open()
            ->whereKey($transaction->cash_drawer_session_id)
            ->lockForUpdate()
            ->first();

        if (!$session) {
            return;
        }

        $refundAmount = $saleMovement->amount;
        $balanceBefore = $session->expected_cash;

        if ($refundAmount > $balanceBefore) {
            throw ValidationException::withMessages([
                'cash_drawer' => ['Saldo cash drawer tidak cukup untuk mencatat refund transaksi ini.'],
            ]);
        }

        $balanceAfter = $balanceBefore - $refundAmount;

        $session->update([
            'expected_cash' => $balanceAfter,
            'cash_refunds_total' => $session->cash_refunds_total + $refundAmount,
        ]);

        $movement = $session->recordMovement(
            'cash_refund',
            $refundAmount,
            $balanceBefore,
            $balanceAfter,
            $user,
            'Refund void transaksi ' . $transaction->nomor_transaksi,
            $transaction->id,
            'transaction'
        );

        \App\Models\ActivityLog::log(
            'cash_drawer_cash_refund',
            "Cash drawer #{$session->id} recorded cash refund for voided transaction {$transaction->nomor_transaksi}.",
            $session,
            [
                'movement_id' => $movement->id,
                'transaction_id' => $transaction->id,
                'transaction_number' => $transaction->nomor_transaksi,
                'amount' => $refundAmount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
            ]
        );
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
