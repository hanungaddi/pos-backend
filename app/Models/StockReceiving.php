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
        'nomor_faktur',
        'catatan',
        'user_id',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockReceivingItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
