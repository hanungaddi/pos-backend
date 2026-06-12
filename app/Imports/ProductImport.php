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
        // Ambil dan rapikan data dari Excel
        $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : null;
        $namaExcel = isset($row['nama']) ? trim((string) $row['nama']) : null;

        // Normalisasi nama untuk perbandingan
        $namaExcelNormalized = strtolower($namaExcel ?? '');
        try {
            $existingProduct = null;

            // Jika barcode dari Excel ada, cek ke database
            if (!empty($barcode)) {
                $existingProduct = Product::where('barcode', $barcode)->first();
            }

            // Jika barcode sudah ada di database
            if ($existingProduct) {
                
                $namaDB = trim((string) $existingProduct->nama);
                $namaDBNormalized = strtolower($namaDB);

                // Jika barcode sama dan nama sama, update data saja
                if ($namaDBNormalized === $namaExcelNormalized) {
                    $existingProduct->update([
                        'nama'       => $namaExcel,
                        'stok'       => $row['stok'] ?? 0,
                        'harga_jual' => $row['harga_jual'] ?? 0,
                        'harga_beli' => $row['harga_beli'] ?? 0,
                        'status'     => $row['status'] ?? 'active',
                        'margin'     => 0,
                    ]);

                    $this->imported++;

                    return $existingProduct;
                }

                // Jika barcode sama tapi nama berbeda,
                // maka buat barcode baru untuk produk baru
                $barcode = Product::generateUniqueBarcode();
            }

            // Jika barcode kosong dari Excel, buat barcode baru
            if (empty($barcode)) {
                $barcode = Product::generateUniqueBarcode();
            }

            // Buat produk baru
            $product = new Product([
                'nama'       => $namaExcel,
                'stok'       => $row['stok'] ?? 0,
                'harga_jual' => $row['harga_jual'] ?? 0,
                'harga_beli' => $row['harga_beli'] ?? 0,
                'status'     => $row['status'] ?? 'active',
                'barcode'    => $barcode,
                'margin'     => 0,
            ]);

            $product->save();
            $this->imported++;
            return $product;
        } catch (\Exception $e) {
            Log::error('Import Product failed', [
                'row'   => $row,
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