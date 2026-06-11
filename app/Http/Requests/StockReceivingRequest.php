<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'nomor_faktur' => ['nullable', 'string', 'max:255'],
            'tanggal_terima' => ['nullable', 'date'],
            'catatan' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,completed'],
            'nilai_faktur' => ['nullable', 'integer', 'min:0'],
            'status_pembayaran' => ['nullable', 'string', 'in:pending,paid'],
        ];
    }
}
