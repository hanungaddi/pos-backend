<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivingPaymentRequest extends FormRequest
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
            'stock_receiving_id' => 'required|exists:stock_receivings,id',
            'nominal' => 'required|integer|min:1',
            'tanggal_bayar' => 'required|date',
            'cash_account_id' => 'required|exists:cash_accounts,id',
            'metode_pembayaran' => 'required|string|max:50',
            'catatan' => 'nullable|string',
        ];
    }
}
