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
            'nomor_faktur' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.kuantitas' => ['required', 'integer', 'min:1'],
        ];
    }
}
