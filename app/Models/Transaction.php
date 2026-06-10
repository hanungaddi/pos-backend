<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'cash_drawer_session_id',
        'nomor_transaksi',
        'tipe',
        'cash_account_id',
        'target_account_id',
        'kategori',
        'referensi_id',
        'referensi_tipe',
        'subtotal',
        'pajak',
        'diskon',
        'total',
        'status',
        'metode_pembayaran',
        'nominal_bayar',
        'kembalian',
        'jenis_kartu',
        'nomor_kartu_akhir',
        'referensi_edc',
        'catatan_void',
        'void_by',
        'voided_at',
        'is_offline',
        'offline_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'pajak' => 'integer',
            'diskon' => 'integer',
            'total' => 'integer',
            'nominal_bayar' => 'integer',
            'kembalian' => 'integer',
            'cash_drawer_session_id' => 'integer',
            'cash_account_id' => 'integer',
            'target_account_id' => 'integer',
            'referensi_id' => 'integer',
            'is_offline' => 'boolean',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::created(function (Transaction $transaction) {
            if ($transaction->cash_account_id) {
                $amount = $transaction->total;
                if (in_array($transaction->tipe, ['cash_out', 'transfer', 'supplier_payment'])) {
                    $amount = -$amount;
                }
                if ($transaction->status !== 'void') {
                    self::adjustCashAccountBalance($transaction->cash_account_id, $amount);
                }
            }

            if ($transaction->tipe === 'transfer' && $transaction->target_account_id && $transaction->status !== 'void') {
                self::adjustCashAccountBalance($transaction->target_account_id, $transaction->total);
            }
        });

        static::updating(function (Transaction $transaction) {
            $oldCashAccountId = $transaction->getOriginal('cash_account_id');
            $newCashAccountId = $transaction->cash_account_id;
            
            $oldTargetAccountId = $transaction->getOriginal('target_account_id');
            $newTargetAccountId = $transaction->target_account_id;

            $oldTotal = (int) $transaction->getOriginal('total');
            $newTotal = (int) $transaction->total;

            $oldTipe = $transaction->getOriginal('tipe');
            $newTipe = $transaction->tipe;

            $oldStatus = $transaction->getOriginal('status');
            $newStatus = $transaction->status;

            // 1. REVERSE OLD TRANSACTION EFFECT
            if ($oldCashAccountId && $oldStatus !== 'void') {
                $oldAmount = $oldTotal;
                if (in_array($oldTipe, ['cash_out', 'transfer', 'supplier_payment'])) {
                    $oldAmount = -$oldAmount;
                }
                self::adjustCashAccountBalance($oldCashAccountId, -$oldAmount);
            }
            if ($oldTipe === 'transfer' && $oldTargetAccountId && $oldStatus !== 'void') {
                self::adjustCashAccountBalance($oldTargetAccountId, -$oldTotal);
            }

            // 2. APPLY NEW TRANSACTION EFFECT
            if ($newCashAccountId && $newStatus !== 'void') {
                $newAmount = $newTotal;
                if (in_array($newTipe, ['cash_out', 'transfer', 'supplier_payment'])) {
                    $newAmount = -$newAmount;
                }
                self::adjustCashAccountBalance($newCashAccountId, $newAmount);
            }
            if ($newTipe === 'transfer' && $newTargetAccountId && $newStatus !== 'void') {
                self::adjustCashAccountBalance($newTargetAccountId, $newTotal);
            }
        });

        static::saved(function (Transaction $transaction) {
            if ($transaction->referensi_tipe === 'receiving' && $transaction->referensi_id) {
                self::recalculateReceivingPayment($transaction->referensi_id);
            }
        });

        static::deleted(function (Transaction $transaction) {
            if ($transaction->status !== 'void') {
                if ($transaction->cash_account_id) {
                    $amount = $transaction->total;
                    if (in_array($transaction->tipe, ['cash_out', 'transfer', 'supplier_payment'])) {
                        $amount = -$amount;
                    }
                    self::adjustCashAccountBalance($transaction->cash_account_id, -$amount);
                }

                if ($transaction->tipe === 'transfer' && $transaction->target_account_id) {
                    self::adjustCashAccountBalance($transaction->target_account_id, -$transaction->total);
                }
            }

            if ($transaction->referensi_tipe === 'receiving' && $transaction->referensi_id) {
                self::recalculateReceivingPayment($transaction->referensi_id);
            }
        });
    }

    public static function adjustCashAccountBalance(int $accountId, int $amount): void
    {
        $account = \App\Models\CashAccount::find($accountId);
        if ($account) {
            $account->increment('saldo', $amount);
        }
    }

    public static function recalculateReceivingPayment(int $receivingId): void
    {
        $receiving = StockReceiving::find($receivingId);
        if (!$receiving) {
            return;
        }

        // Sum the total of active (not void) supplier_payment and supplier_return_credit transactions
        $totalPaid = Transaction::where('referensi_id', $receivingId)
            ->where('referensi_tipe', 'receiving')
            ->whereIn('tipe', ['supplier_payment', 'supplier_return_credit'])
            ->where('status', '!=', 'void')
            ->sum('total');

        $nilaiFaktur = $receiving->nilai_faktur ?? 0;

        if ($totalPaid <= 0) {
            $receiving->status_pembayaran = 'unpaid';
        } elseif ($totalPaid >= $nilaiFaktur) {
            $receiving->status_pembayaran = 'paid';
        } else {
            $receiving->status_pembayaran = 'partial';
        }

        $receiving->saveQuietly();
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashDrawerSession(): BelongsTo
    {
        return $this->belongsTo(CashDrawerSession::class);
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }
}
