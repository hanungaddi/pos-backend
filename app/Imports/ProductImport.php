<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Throwable;

class ProductImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    use Importable;

    public function __construct(
        protected int $importJobId
    ) {}

    public function model(array $row)
    {
        $barcode = isset($row['barcode']) ? trim((string) $row['barcode']) : null;
        $namaExcel = isset($row['nama']) ? trim((string) $row['nama']) : null;

        if (empty($namaExcel)) {
            $this->incrementProgress(imported: 0, skipped: 1);
            return null;
        }

        $namaExcelNormalized = strtolower($namaExcel);

        try {
            $existingProduct = null;

            if (!empty($barcode)) {
                $existingProduct = Product::where('barcode', $barcode)->first();
            }

            if ($existingProduct) {
                $namaDB = trim((string) $existingProduct->nama);
                $namaDBNormalized = strtolower($namaDB);

                if ($namaDBNormalized === $namaExcelNormalized) {
                    $existingProduct->update([
                        'nama'       => $namaExcel,
                        'stok'       => $row['stok'] ?? 0,
                        'harga_jual' => $row['harga_jual'] ?? 0,
                        'harga_beli' => $row['harga_beli'] ?? 0,
                        'status'     => $row['status'] ?? 'active',
                        'margin'     => 0,
                    ]);

                    $this->incrementProgress(imported: 1, skipped: 0);

                    return null;
                }

                $barcode = Product::generateUniqueBarcode();
            }

            if (empty($barcode)) {
                $barcode = Product::generateUniqueBarcode();
            }

            Product::create([
                'nama'       => $namaExcel,
                'stok'       => $row['stok'] ?? 0,
                'harga_jual' => $row['harga_jual'] ?? 0,
                'harga_beli' => $row['harga_beli'] ?? 0,
                'status'     => $row['status'] ?? 'active',
                'barcode'    => $barcode,
                'margin'     => 0,
            ]);

            $this->incrementProgress(imported: 1, skipped: 0);

            return null;
        } catch (Throwable $e) {
            Log::error('Import Product row failed', [
                'import_job_id' => $this->importJobId,
                'row'           => $row,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            $this->incrementProgress(imported: 0, skipped: 1);

            return null;
        }
    }

    private function incrementProgress(int $imported, int $skipped): void
    {
        DB::table('product_import_jobs')
            ->where('id', $this->importJobId)
            ->update([
                'processed_rows' => DB::raw('processed_rows + 1'),
                'imported_rows'  => DB::raw('imported_rows + ' . $imported),
                'skipped_rows'   => DB::raw('skipped_rows + ' . $skipped),
                'status'         => 'processing',
                'updated_at'     => now(),
            ]);
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function failed(Throwable $e): void
    {
        ProductImportJob::where('id', $this->importJobId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
            'updated_at'    => now(),
        ]);

        Log::error('Product import chunk failed', [
            'import_job_id' => $this->importJobId,
            'error'         => $e->getMessage(),
            'trace'         => $e->getTraceAsString(),
        ]);
    }
}