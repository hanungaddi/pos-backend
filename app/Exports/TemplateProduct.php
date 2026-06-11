<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class TemplateProduct implements FromCollection, WithHeadings
{
    public function collection()
    {
        // Kosongkan Data Untuk Template Download
        return new Collection([]);
    }

    public function headings(): array
    {
        return [
            'nama',
            'stok',
            'harga_jual',
            'harga_beli',
            'barcode',
            'status',
            // 'harga_member',
            // 'harga_grosir',
            // 'satuan_beli',
            // 'satuan_jual',
            // 'is_grosir',
            // 'min_pembelian_grosir',
            // 'min_stok'
        ];
    }
}