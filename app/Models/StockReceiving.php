<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReceiving extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'purchase_order_id',
        'nomor_penerimaan',
        'supplier',
        'supplier_id',
        'nomor_faktur',
        'tanggal_terima',
        'catatan',
        'user_id',
        'status',
        'nilai_faktur',
        'status_pembayaran',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'purchase_order_id' => 'integer',
            'supplier_id' => 'integer',
            'user_id' => 'integer',
            'nilai_faktur' => 'integer',
            'tanggal_terima' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceivingItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier_relationship(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class, 'referensi_id')
            ->where('referensi_tipe', 'receiving')
            ->where('tipe', 'supplier_payment');
    }
}
