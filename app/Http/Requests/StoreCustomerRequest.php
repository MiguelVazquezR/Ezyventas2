<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
            'initial_balance' => 'nullable|numeric',
        ];
    }
}