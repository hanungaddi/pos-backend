<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama',
        'tipe',
        'saldo',
    ];

    protected function casts(): array
    {
        return [
            'saldo' => 'integer',
        ];
    }
}
