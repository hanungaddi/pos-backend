<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReceivingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_receiving_id',
        'product_id',
        'kuantitas',
        'harga_beli',
        'update_harga_jual',
        'harga_jual_baru',
        'margin_baru',
    ];

    protected $casts = [
        'harga_beli' => 'integer',
        'kuantitas' => 'integer',
        'update_harga_jual' => 'boolean',
        'harga_jual_baru' => 'integer',
        'margin_baru' => 'float',
    ];

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(StockReceiving::class, 'stock_receiving_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
