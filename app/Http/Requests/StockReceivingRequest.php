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
            'supplier' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'nomor_faktur' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:draft,completed'],
            'nilai_faktur' => ['nullable', 'integer', 'min:0'],
            'status_pembayaran' => ['nullable', 'string', 'in:pending,paid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.kuantitas' => ['required', 'integer', 'min:1'],
        ];
    }
}
