<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'kuantitas' => ['required', 'integer', 'not_in:0'], // Can be positive (incoming) or negative (outgoing/loss)
            'alasan' => ['required', 'string', 'max:1000'],
        ];
    }
}
