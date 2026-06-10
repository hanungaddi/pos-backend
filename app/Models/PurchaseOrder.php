<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'nomor_po',
        'supplier_id',
        'supplier_name',
        'tanggal_po',
        'status',
        'nilai_estimasi',
        'catatan',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'supplier_id' => 'integer',
            'nilai_estimasi' => 'integer',
            'user_id' => 'integer',
            'tanggal_po' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
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
