<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:255',
            'item_description' => 'required|string|max:255',
            'reported_problems' => 'required|string',
            'promised_at' => 'nullable|date',
            'technician_name' => 'nullable|string|max:255',
            'technician_diagnosis' => 'nullable|string',
            'final_total' => 'nullable|numeric|min:0',
            'custom_fields' => 'nullable|array',
        ];
    }
}