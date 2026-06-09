<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    protected $fillable = ['nama', 'store_id'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
