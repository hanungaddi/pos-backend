<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductPriceLog;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        // Check if price-related fields were changed
        if ($product->isDirty(['harga_beli', 'harga_jual', 'margin'])) {
            ProductPriceLog::create([
                'product_id' => $product->id,
                'user_id' => Auth::id(), // Will be null if triggered from console/CLI seeder
                'harga_beli_lama' => (int) $product->getOriginal('harga_beli'),
                'harga_beli_baru' => (int) $product->harga_beli,
                'harga_jual_lama' => (int) $product->getOriginal('harga_jual'),
                'harga_jual_baru' => (int) $product->harga_jual,
                'margin_lama' => $product->getOriginal('margin') !== null ? (float) $product->getOriginal('margin') : null,
                'margin_baru' => $product->margin !== null ? (float) $product->margin : null,
                'sumber' => $product->price_log_sumber ?? 'manual',
                'referensi_id' => $product->price_log_referensi_id ?? null,
                'catatan' => $product->price_log_catatan ?? null,
            ]);
        }
    }
}
