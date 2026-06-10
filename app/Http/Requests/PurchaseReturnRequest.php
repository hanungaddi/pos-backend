<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'stock_receiving_id' => 'nullable|exists:stock_receivings,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'tanggal_retur' => 'required|date',
            'catatan' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.kuantitas' => 'required|integer|min:1',
            'items.*.harga_beli' => 'required|integer|min:0',
        ];
    }
}
