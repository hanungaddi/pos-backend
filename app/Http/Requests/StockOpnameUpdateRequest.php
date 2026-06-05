<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockOpnameUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'catatan' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:draft,completed'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.stok_fisik' => ['required', 'integer', 'min:0'],
            'items.*.alasan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
