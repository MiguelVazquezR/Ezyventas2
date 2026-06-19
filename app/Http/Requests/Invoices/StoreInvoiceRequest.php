<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create invoices');
    }

    public function rules(): array
    {
        return [
            // --- Receiver (receptor) ---
            'receiver_rfc'          => ['required', 'string', 'size:13'],
            'receiver_legal_name'   => ['required', 'string', 'max:255'],
            'receiver_tax_regime'   => ['required', 'string', 'max:10'],
            'receiver_postal_code'  => ['required', 'string', 'size:5'],
            'cfdi_use'              => ['required', 'string', 'max:10'],

            // --- Payment ---
            'payment_form'          => ['required', 'string', 'max:5'],
            'payment_method'        => ['required', 'string', 'max:5'],
            'currency'              => ['nullable', 'string', 'max:5'],

            // --- Items ---
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.description'   => ['required', 'string', 'max:255'],
            'items.*.quantity'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'items.*.sat_product_code' => ['required', 'string', 'max:15'],
            'items.*.sat_unit_code' => ['required', 'string', 'max:10'],
            'items.*.tax_type'      => ['nullable', 'string', 'max:5'],
            'items.*.tax_rate'      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            // --- Optional relations ---
            'customer_id'           => ['nullable', 'integer', 'exists:customers,id'],
            'series'                => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'receiver_rfc.required'          => 'El campo RFC es obligatorio.',
            'receiver_rfc.size'              => 'El RFC debe tener exactamente 13 caracteres.',
            'receiver_legal_name.required'   => 'La razón social es obligatoria.',
            'receiver_tax_regime.required'   => 'El régimen fiscal es obligatorio.',
            'receiver_postal_code.required'  => 'El código postal es obligatorio.',
            'receiver_postal_code.size'      => 'El código postal debe tener 5 dígitos.',
            'cfdi_use.required'              => 'El uso de CFDI es obligatorio.',
            'payment_form.required'          => 'La forma de pago es obligatoria.',
            'payment_method.required'        => 'El método de pago es obligatorio.',
            'items.required'                 => 'Agrega al menos un concepto a la factura.',
            'items.min'                      => 'Agrega al menos un concepto a la factura.',
            'items.*.description.required'   => 'La descripción del concepto es obligatoria.',
            'items.*.quantity.required'      => 'La cantidad es obligatoria.',
            'items.*.quantity.gt'            => 'La cantidad debe ser mayor a cero.',
            'items.*.unit_price.required'    => 'El precio unitario es obligatorio.',
            'items.*.unit_price.min'         => 'El precio unitario no puede ser negativo.',
            'items.*.sat_product_code.required' => 'La clave de producto SAT es obligatoria.',
            'items.*.sat_unit_code.required' => 'La clave de unidad SAT es obligatoria.',
            'items.*.tax_rate.min'           => 'La tasa de impuesto no puede ser negativa.',
            'items.*.tax_rate.max'           => 'La tasa de impuesto no puede exceder 1 (100 %).',
            'items.*.discount_amount.min'    => 'El descuento no puede ser negativo.',
        ];
    }
}
