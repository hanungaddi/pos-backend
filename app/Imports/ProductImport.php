<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Facades\Log;

class ProductImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    public int $imported = 0;
    public int $skipped = 0;

    /**
     * Mapping row Excel menjadi model Product
     */
    public function model(array $row)
    {
        // Ambil barcode dari Excel, jika duplicate di DB generate baru
        $barcode = $row['barcode'] ?? null;
        if (!$barcode || Product::where('barcode', $barcode)->exists()) {
            $barcode = Product::generateUniqueBarcode();
        }

        $data = [
            'nama'       => $row['nama'] ?? null,
            'stok'       => $row['stok'] ?? 0,
            'harga_jual' => $row['harga_jual'] ?? 0,
            'harga_beli' => $row['harga_beli'] ?? 0,
            'status'     => $row['status'] ?? 'active',
            'barcode'    => $barcode,
            'margin'     => 0, // skip margin calc saat import
            // 'harga_member' => $row['harga_member'] ?? 0,
            // 'harga_grosir' => $row['harga_grosir'] ?? 0,
            // 'satuan_beli' => $row['satuan_beli'] ?? 0,
            // 'satuan_jual' => $row['satuan_jual'] ?? 0,
            // 'is_grosir' => $row['is_grosir'] ?? 0,
            // 'min_pembelian_grosir' => $row['min_pembelian_grosir'] ?? 0,
            // 'min_stok' => $row['min_stock'] ?? 0
        ];

        try {
            $product = new Product($data);
            $product->save();
            $this->imported++;
            return $product;
        } catch (\Exception $e) {
            Log::error('Import Product failed', [
                'row' => $row,
                'error' => $e->getMessage()
            ]);
            $this->skipped++;
            return null;
        }
    }

    public function chunkSize(): int
    {
        return 500; // bisa diubah sesuai memory server
    }
}