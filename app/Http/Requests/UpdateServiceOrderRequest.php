<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceOrderRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_address' => ['nullable', 'array'],
            'customer_address.street' => ['nullable', 'string', 'max:255'],
            'customer_address.city' => ['nullable', 'string', 'max:255'],
            'item_description' => 'required|string|max:255',
            'reported_problems' => 'required|string',
            'promised_at' => 'nullable|date',
            'custom_fields' => 'nullable|array',
            'initial_evidence_images' => 'nullable|array|max:5',
            'initial_evidence_images.*' => 'image|max:2048',
            'deleted_media_ids' => 'nullable|array',
            'deleted_media_ids.*' => 'integer|exists:media,id',
            'items' => 'nullable|array',
            'items.*.itemable_id' => 'nullable',
            'items.*.itemable_type' => 'nullable|string',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.line_total' => 'required|numeric|min:0',
            'assign_technician' => ['required', 'boolean'],
            'technician_name' => ['required_if:assign_technician,true', 'nullable', 'string', 'max:255'],
            'technician_commission_type' => ['required_if:assign_technician,true', 'nullable', Rule::in(['percentage', 'fixed'])],
            'technician_commission_value' => ['required_if:assign_technician,true', 'nullable', 'numeric', 'min:0'],

            // --- REGLAS PARA DESCUENTOS ---
            'subtotal' => 'required|numeric|min:0',
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_value' => 'nullable|numeric|min:0',
            'discount_amount' => 'required|numeric|min:0',
            'final_total' => 'required|numeric|min:0',
        ];
    }
}