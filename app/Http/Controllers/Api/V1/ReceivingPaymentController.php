<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivingPaymentRequest;
use App\Models\Transaction;
use App\Models\StockReceiving;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivingPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->where('tipe', 'supplier_payment')
            ->where('status', '!=', 'void');

        if ($request->filled('stock_receiving_id')) {
            $query->where('referensi_id', $request->integer('stock_receiving_id'))
                  ->where('referensi_tipe', 'receiving');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->string('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->string('end_date'));
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 15));

        return $this->responsePaginated($payments);
    }

    public function store(ReceivingPaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $stockReceivingId = $validated['receiving_id'] ?? $validated['stock_receiving_id'];
        $nominal = $validated['jumlah_bayar'] ?? $validated['nominal'];

        $receiving = StockReceiving::find($stockReceivingId);
        if (!$receiving) {
            return response()->json(['message' => 'Penerimaan barang tidak ditemukan.'], 404);
        }

        if ($receiving->status !== 'completed') {
            return response()->json(['message' => 'Tidak dapat melakukan pembayaran pada penerimaan barang yang belum selesai.'], 422);
        }

        // Calculate total paid so far
        $totalPaid = Transaction::where('referensi_id', $receiving->id)
            ->where('referensi_tipe', 'receiving')
            ->whereIn('tipe', ['supplier_payment', 'supplier_return_credit'])
            ->where('status', '!=', 'void')
            ->sum('total');

        $sisaHutang = max(0, ($receiving->nilai_faktur ?? 0) - $totalPaid);

        if ($nominal > $sisaHutang) {
            return response()->json([
                'message' => "Jumlah pembayaran (Rp {$nominal}) melebihi sisa hutang (Rp {$sisaHutang})."
            ], 422);
        }

        $payment = DB::transaction(function () use ($stockReceivingId, $nominal, $validated, $request, $receiving) {
            $nomorTransaksi = $this->generateNomorTransaksi();

            $payment = Transaction::create([
                'store_id' => $request->user()->store_id,
                'user_id' => $request->user()->id,
                'nomor_transaksi' => $nomorTransaksi,
                'tipe' => 'supplier_payment',
                'cash_account_id' => $validated['cash_account_id'],
                'kategori' => 'pembelian_supplier',
                'referensi_id' => $stockReceivingId,
                'referensi_tipe' => 'receiving',
                'total' => $nominal,
                'status' => 'completed',
                'metode_pembayaran' => $validated['metode_pembayaran'],
                'created_at' => $validated['tanggal_bayar'],
                'catatan_void' => $validated['catatan'] ?? null,
            ]);

            return $payment;
        });

        ActivityLog::log(
            'create_supplier_payment',
            "Payment of Rp {$payment->total} for invoice '{$receiving->nomor_faktur}' was recorded.",
            $payment,
            ['new' => $payment->toArray()]
        );

        return $this->responseSuccess($payment, 'Pembayaran berhasil dicatat.', 201);
    }

    public function show(int $id): JsonResponse
    {
        $payment = Transaction::where('tipe', 'supplier_payment')->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Data pembayaran tidak ditemukan.'], 404);
        }

        return $this->responseSuccess($payment, 'Detail pembayaran.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $payment = Transaction::where('tipe', 'supplier_payment')->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Data pembayaran tidak ditemukan.'], 404);
        }

        if ($payment->status === 'void') {
            return response()->json(['message' => 'Pembayaran yang sudah dibatalkan tidak dapat diubah.'], 422);
        }

        $validated = $request->validate([
            'nominal' => 'required_without:jumlah_bayar|integer|min:1',
            'jumlah_bayar' => 'required_without:nominal|integer|min:1',
            'tanggal_bayar' => 'required|date',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'metode_pembayaran' => 'required|string|max:50',
            'catatan' => 'nullable|string',
        ]);

        $nominal = $validated['jumlah_bayar'] ?? $validated['nominal'];

        $receiving = StockReceiving::find($payment->referensi_id);
        if ($receiving) {
            $totalPaid = Transaction::where('referensi_id', $receiving->id)
                ->where('referensi_tipe', 'receiving')
                ->whereIn('tipe', ['supplier_payment', 'supplier_return_credit'])
                ->where('status', '!=', 'void')
                ->where('id', '!=', $payment->id)
                ->sum('total');

            $sisaHutang = max(0, ($receiving->nilai_faktur ?? 0) - $totalPaid);

            if ($nominal > $sisaHutang) {
                return response()->json([
                    'message' => "Jumlah pembayaran (Rp {$nominal}) melebihi sisa hutang (Rp {$sisaHutang})."
                ], 422);
            }
        }

        $oldData = $payment->toArray();

        DB::transaction(function () use ($validated, $payment, $nominal) {
            $payment->update([
                'total' => $nominal,
                'cash_account_id' => $validated['cash_account_id'],
                'metode_pembayaran' => $validated['metode_pembayaran'],
                'created_at' => $validated['tanggal_bayar'],
                'catatan_void' => $validated['catatan'] ?? $payment->catatan_void,
            ]);
        });

        ActivityLog::log(
            'update_supplier_payment',
            "Payment '{$payment->nomor_transaksi}' was updated.",
            $payment,
            ['old' => $oldData, 'new' => $payment->toArray()]
        );

        return $this->responseSuccess($payment, 'Pembayaran berhasil diperbarui.');
    }

    public function outstanding(Request $request): JsonResponse
    {
        $query = StockReceiving::query()
            ->with(['supplier_relationship'])
            ->where('status', 'completed')
            ->whereIn('status_pembayaran', ['pending', 'partially_paid', 'unpaid', 'partial']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        $receivings = $query->latest()->get();

        return $this->responseSuccess($receivings, 'Outstanding Stock Receivings.');
    }

    public function paymentSummary(int $id): JsonResponse
    {
        $receiving = StockReceiving::find($id);
        if (!$receiving) {
            return response()->json(['message' => 'Data penerimaan tidak ditemukan.'], 404);
        }

        $payments = Transaction::where('referensi_id', $id)
            ->where('referensi_tipe', 'receiving')
            ->whereIn('tipe', ['supplier_payment', 'supplier_return_credit'])
            ->where('status', '!=', 'void')
            ->get();

        $totalPaid = $payments->sum('total');
        $sisaHutang = max(0, ($receiving->nilai_faktur ?? 0) - $totalPaid);

        $statusPembayaran = $receiving->status_pembayaran;
        if ($statusPembayaran === 'unpaid') {
            $statusPembayaran = 'pending';
        } elseif ($statusPembayaran === 'partial') {
            $statusPembayaran = 'partially_paid';
        }

        $formattedPayments = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'jumlah' => $payment->total,
                'metode' => $payment->metode_pembayaran,
                'tanggal' => $payment->created_at ? $payment->created_at->toDateString() : null,
            ];
        });

        return response()->json([
            'receiving_id' => $receiving->id,
            'nomor_penerimaan' => $receiving->nomor_penerimaan,
            'total_faktur' => $receiving->nilai_faktur ?? 0,
            'total_dibayar' => $totalPaid,
            'sisa_hutang' => $sisaHutang,
            'status_pembayaran' => $statusPembayaran,
            'payments' => $formattedPayments,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $payment = Transaction::where('tipe', 'supplier_payment')->find($id);

        if (!$payment) {
            return response()->json(['message' => 'Data pembayaran tidak ditemukan.'], 404);
        }

        if ($payment->status === 'void') {
            return response()->json(['message' => 'Pembayaran sudah dalam status batal (void).'], 422);
        }

        $oldData = $payment->toArray();

        DB::transaction(function () use ($payment, $request) {
            $payment->update([
                'status' => 'void',
                'catatan_void' => $request->input('alasan') ?? 'Voided by user',
                'void_by' => $request->user()->id,
                'voided_at' => now(),
            ]);
        });

        ActivityLog::log(
            'void_supplier_payment',
            "Payment '{$payment->nomor_transaksi}' was voided.",
            $payment,
            ['old' => $oldData, 'new' => $payment->toArray()]
        );

        return $this->responseSuccess($payment, 'Pembayaran berhasil dibatalkan (void).');
    }

    private function generateNomorTransaksi(): string
    {
        do {
            $number = 'TX-PAY-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (Transaction::where('nomor_transaksi', $number)->exists());

        return $number;
    }
}
