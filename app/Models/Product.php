<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'brand_id',
        'nama',
        'merek',
        'barcode',
        'stok',
        'harga',
        'status',
        'image_path',
    ];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'stok' => 'integer',
            'harga' => 'integer',
        ];
    }

    /**
     * Get the fully qualified URL to the product's image.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    /**
     * Generate a unique EAN-13 barcode for internal use.
     */
    public static function generateUniqueBarcode(): string
    {
        do {
            // EAN-13 internal country prefix is typically '20' to '29' (we use '20')
            // Generate 10 random digits to make 12 digits total
            $digits = '20' . str_pad((string)random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            
            // Calculate EAN-13 checksum (13th digit)
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $digit = (int)$digits[$i];
                $sum += ($i % 2 === 0) ? $digit : $digit * 3;
            }
            $remainder = $sum % 10;
            $checksum = $remainder === 0 ? 0 : 10 - $remainder;
            
            $barcode = $digits . $checksum;
        } while (self::where('barcode', $barcode)->exists());

        return $barcode;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
