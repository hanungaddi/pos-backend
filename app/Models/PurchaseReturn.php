<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'nomor_retur',
        'stock_receiving_id',
        'supplier_id',
        'tanggal_retur',
        'total_nominal',
        'catatan',
        'status',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'stock_receiving_id' => 'integer',
            'supplier_id' => 'integer',
            'tanggal_retur' => 'date',
            'total_nominal' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function stockReceiving(): BelongsTo
    {
        return $this->belongsTo(StockReceiving::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
