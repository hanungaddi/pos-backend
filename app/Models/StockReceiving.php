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
        'nomor_penerimaan',
        'supplier',
        'supplier_id',
        'nomor_faktur',
        'catatan',
        'user_id',
        'status',
        'nilai_faktur',
        'status_pembayaran',
    ];

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
}
