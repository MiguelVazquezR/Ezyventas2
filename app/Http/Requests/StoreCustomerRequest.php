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
            'tax_regime' => 'nullable|string|max:10',
            'fiscal_address' => 'nullable|array',
            'fiscal_address.street' => 'nullable|string|max:255',
            'fiscal_address.exterior_number' => 'nullable|string|max:20',
            'fiscal_address.interior_number' => 'nullable|string|max:20',
            'fiscal_address.neighborhood' => 'nullable|string|max:255',
            'fiscal_address.zip_code' => 'nullable|string|max:10',
            'fiscal_address.city' => 'nullable|string|max:255',
            'fiscal_address.state' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'credit_limit' => 'nullable|numeric|min:0',
            'initial_balance' => 'nullable|numeric',
        ];
    }
}