<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceOrderRequest extends FormRequest
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
            'final_total' => 'required|numeric|min:0', // El total ahora es requerido
            'custom_fields' => 'nullable|array',
            'initial_evidence_images' => 'nullable|array|max:5',
            'initial_evidence_images.*' => 'image|max:2048',
            'items' => 'nullable|array',
            'items.*.itemable_id' => 'nullable',
            'items.*.itemable_type' => 'nullable|string',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.line_total' => 'required|numeric|min:0',
        ];
    }
}
