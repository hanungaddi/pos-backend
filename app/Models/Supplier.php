<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'nama',
        'email',
        'nomor_telepon',
        'alamat',
    ];

    public function stockReceivings(): HasMany
    {
        return $this->hasMany(StockReceiving::class);
    }
}
