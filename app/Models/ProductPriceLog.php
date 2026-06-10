<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceLog extends Model
{
    use HasFactory;

    // This log only tracks creation timestamps
    const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'user_id',
        'harga_beli_lama',
        'harga_beli_baru',
        'harga_jual_lama',
        'harga_jual_baru',
        'margin_lama',
        'margin_baru',
        'sumber',
        'referensi_id',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'user_id' => 'integer',
            'harga_beli_lama' => 'integer',
            'harga_beli_baru' => 'integer',
            'harga_jual_lama' => 'integer',
            'harga_jual_baru' => 'integer',
            'margin_lama' => 'float',
            'margin_baru' => 'float',
            'referensi_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
