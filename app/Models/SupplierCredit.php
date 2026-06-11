<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'supplier_id',
        'amount',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'supplier_id' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
