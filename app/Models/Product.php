<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    public ?string $price_log_sumber = null;
    public ?int $price_log_referensi_id = null;
    public ?string $price_log_catatan = null;

    protected $fillable = [
        'category_id',
        'brand_id',
        'nama',
        'merek',
        'barcode',
        'stok',
        'harga',
        'harga_beli',
        'harga_jual',
        'margin',
        'status',
        'image_path',
    ];

    protected $appends = ['image_url', 'harga'];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'brand_id' => 'integer',
            'stok' => 'integer',
            'harga' => 'integer',
            'harga_beli' => 'integer',
            'harga_jual' => 'integer',
            'margin' => 'float',
        ];
    }

    protected static function booted()
    {
        static::observe(\App\Observers\ProductObserver::class);

        static::saving(function (Product $product) {
            $hargaBeli = (int) ($product->harga_beli ?? 0);
            // Fallback to harga if harga_jual is not set
            $hargaJual = (int) ($product->harga_jual ?? $product->harga ?? 0);
            $margin = $product->margin !== null ? (float) $product->margin : null;

            if ($hargaBeli > 0) {
                if ($margin === null) {
                    $product->margin = round((($hargaJual - $hargaBeli) / $hargaBeli) * 100, 2);
                } elseif ($hargaJual === 0) {
                    $product->harga_jual = (int) round($hargaBeli * (1 + $margin / 100));
                } else {
                    if ($product->isDirty('margin') && !$product->isDirty('harga_jual')) {
                        $product->harga_jual = (int) round($hargaBeli * (1 + $margin / 100));
                    } else {
                        $product->margin = round((($hargaJual - $hargaBeli) / $hargaBeli) * 100, 2);
                    }
                }
            } else {
                $product->margin = 0.00;
            }
        });
    }

    /**
     * Backward-compatible accessor for harga (maps to harga_jual).
     */
    public function getHargaAttribute(): int
    {
        return (int) ($this->attributes['harga_jual'] ?? 0);
    }

    /**
     * Backward-compatible mutator for harga (maps to harga_jual).
     */
    public function setHargaAttribute($value): void
    {
        $this->attributes['harga_jual'] = $value;
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

    public function priceLogs(): HasMany
    {
        return $this->hasMany(ProductPriceLog::class);
    }
}
