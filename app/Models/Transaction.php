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
        'nomor_transaksi',
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
            'is_offline' => 'boolean',
            'voided_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'void_by');
    }
}
