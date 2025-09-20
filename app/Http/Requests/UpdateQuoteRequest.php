<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Las reglas son idénticas a las de creación para este caso
        return [
            'customer_id' => 'required|exists:customers,id',
            'expiry_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'subtotal' => 'required|numeric',
            'total_discount' => 'required|numeric',
            'total_tax' => 'required|numeric',
            'shipping_cost' => 'required|numeric',
            'total_amount' => 'required|numeric',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email|max:255',
            'recipient_phone' => 'nullable|string|max:255',
            'shipping_address' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.itemable_id' => 'required',
            'items.*.itemable_type' => 'required|string',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.line_total' => 'required|numeric|min:0',
            'custom_fields' => 'nullable|array',
        ];
    }
}