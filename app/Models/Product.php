<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['nama', 'merek', 'barcode', 'stok', 'harga', 'status'];

    protected function casts(): array
    {
        return [
            'stok' => 'integer',
            'harga' => 'integer',
        ];
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
