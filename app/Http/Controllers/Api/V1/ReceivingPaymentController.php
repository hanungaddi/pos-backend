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

        $receiving = StockReceiving::find($validated['stock_receiving_id']);
        if (!$receiving) {
            return response()->json(['message' => 'Penerimaan barang tidak ditemukan.'], 404);
        }

        if ($receiving->status !== 'completed') {
            return response()->json(['message' => 'Tidak dapat melakukan pembayaran pada penerimaan barang yang belum selesai.'], 422);
        }

        $payment = DB::transaction(function () use ($validated, $request, $receiving) {
            $nomorTransaksi = $this->generateNomorTransaksi();

            $payment = Transaction::create([
                'store_id' => $request->user()->store_id,
                'user_id' => $request->user()->id,
                'nomor_transaksi' => $nomorTransaksi,
                'tipe' => 'supplier_payment',
                'cash_account_id' => $validated['cash_account_id'],
                'kategori' => 'pembelian_supplier',
                'referensi_id' => $validated['stock_receiving_id'],
                'referensi_tipe' => 'receiving',
                'total' => $validated['nominal'],
                'status' => 'completed',
                'metode_pembayaran' => $validated['metode_pembayaran'],
                'created_at' => $validated['tanggal_bayar'],
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
            'nominal' => 'required|integer|min:1',
            'tanggal_bayar' => 'required|date',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'metode_pembayaran' => 'required|string|max:50',
            'catatan' => 'nullable|string',
        ]);

        $oldData = $payment->toArray();

        DB::transaction(function () use ($validated, $payment) {
            $payment->update([
                'total' => $validated['nominal'],
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
