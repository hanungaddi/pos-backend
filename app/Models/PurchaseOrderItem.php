<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'kuantitas',
        'kuantitas_diterima',
        'harga_estimasi',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'product_id' => 'integer',
            'kuantitas' => 'integer',
            'kuantitas_diterima' => 'integer',
            'harga_estimasi' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
