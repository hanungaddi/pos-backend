<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'nama_produk',
        'barcode',
        'harga_satuan',
        'kuantitas',
        'subtotal',
        'is_taxable',
        'diskon_item',
    ];

    protected function casts(): array
    {
        return [
            'harga_satuan' => 'integer',
            'kuantitas' => 'integer',
            'subtotal' => 'integer',
            'is_taxable' => 'boolean',
            'diskon_item' => 'integer',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
