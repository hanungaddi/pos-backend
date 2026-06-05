<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where('nomor_transaksi', 'like', "%{$search}%");
        }

        $transactions = $query->latest()->paginate($request->integer('per_page', 15));

        return response()->json($transactions);
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

        return response()->json([
            'message' => 'Transaksi berhasil dibuat',
            'data' => $transaction,
        ], 201);
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

        return response()->json(['data' => $transaction]);
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

        return response()->json([
            'message' => 'Item berhasil ditambahkan',
            'data' => $transaction->load('items.product'),
        ]);
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

        return response()->json([
            'message' => 'Item berhasil diperbarui',
            'data' => $transaction->load('items.product'),
        ]);
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

        return response()->json([
            'message' => 'Item berhasil dihapus',
            'data' => $transaction->load('items.product'),
        ]);
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

        return response()->json([
            'message' => 'Transaksi berhasil ditunda',
            'data' => $transaction,
        ]);
    }

    public function recall($id): JsonResponse
    {
        $transaction = Transaction::where('status', 'hold')->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi hold tidak ditemukan.'], 404);
        }

        $transaction->update(['status' => 'draft']);

        return response()->json([
            'message' => 'Transaksi berhasil dipanggil kembali',
            'data' => $transaction->load('items.product'),
        ]);
    }

    public function listOnHold(Request $request): JsonResponse
    {
        $transactions = Transaction::where('status', 'hold')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json(['data' => $transactions]);
    }

    public function payCash(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::whereIn('status', ['draft', 'hold'])->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi aktif tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            // Accept both field name variants (frontend sends cash_received)
            'cash_received'  => ['nullable', 'numeric', 'min:0'],
            'nominal_bayar'  => ['nullable', 'numeric', 'min:0'],
            'diskon'         => ['nullable', 'integer', 'min:0'],
            'pajak'          => ['nullable', 'integer', 'min:0'],
        ]);

        // Normalise: support both cash_received and nominal_bayar
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

            $transaction->update([
                'status'           => 'completed',
                'metode_pembayaran'=> 'cash',
                'nominal_bayar'    => $cashReceived,
                'kembalian'        => $cashReceived - $transaction->total,
            ]);

            return $transaction->fresh(['items.product', 'user']);
        });

        return response()->json([
            'message' => 'Transaksi berhasil dibayar',
            'data'    => $updatedTransaction,
        ]);
    }

    public function payCard(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::whereIn('status', ['draft', 'hold'])->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaksi aktif tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            // Accept both Indonesian field names and frontend English names
            'jenis_kartu'        => ['nullable', 'string', 'in:debit,kredit,credit'],
            'card_type'          => ['nullable', 'string', 'in:debit,kredit,credit'],
            'nomor_kartu_akhir'  => ['nullable', 'string', 'size:4'],
            'last_four'          => ['nullable', 'string', 'size:4'],
            'referensi_edc'      => ['nullable', 'string', 'max:50'],
            'reference_number'   => ['nullable', 'string', 'max:50'],
            'diskon'             => ['nullable', 'integer', 'min:0'],
            'pajak'              => ['nullable', 'integer', 'min:0'],
        ]);

        // Normalise field names
        $jenisKartu       = $validated['jenis_kartu'] ?? $validated['card_type'] ?? 'debit';
        $nomorKartuAkhir  = $validated['nomor_kartu_akhir'] ?? $validated['last_four'] ?? '0000';
        $referensiEdc     = $validated['referensi_edc'] ?? $validated['reference_number'] ?? ('EDC-' . now()->timestamp);

        // Normalise card type (frontend sends 'credit', backend stores 'kredit')
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

        return response()->json([
            'message' => 'Transaksi berhasil dibayar via kartu',
            'data'    => $updatedTransaction,
        ]);
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
            'nominal_bayar' => ['required', 'integer', 'min:0'], // Cash paid
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

            $transaction->update([
                'status' => 'completed',
                'metode_pembayaran' => 'split',
                'nominal_bayar' => $validated['nominal_bayar'] + $validated['card_amount'],
                'kembalian' => $validated['nominal_bayar'] - $validated['cash_amount'],
                'jenis_kartu' => $validated['jenis_kartu'],
                'nomor_kartu_akhir' => $validated['nomor_kartu_akhir'],
                'referensi_edc' => $validated['referensi_edc'],
            ]);

            return $transaction->fresh(['items.product', 'user']);
        });

        return response()->json([
            'message' => 'Transaksi split berhasil dibayar',
            'data' => $updatedTransaction,
        ]);
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

            return $transaction->fresh(['items.product', 'user', 'voidBy']);
        });

        return response()->json([
            'message' => 'Transaksi berhasil di-void',
            'data' => $voidedTransaction,
        ]);
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
