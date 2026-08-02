<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create.invoices');
    }

    public function rules(): array
    {
        return [
            // --- Emitter (emisor) ---
            'fiscal_profile_id'     => ['required', 'integer', 'exists:fiscal_profiles,id'],

            // --- Global CFDI 4.0 attributes ---
            'exportacion'           => ['required', 'string', 'max:5', 'in:01,02,03,04'],
            'tipo_comprobante'      => ['nullable', 'string', 'max:5', 'in:I,E,P,N,T'],

            // --- Receiver (receptor) ---
            'receiver_rfc'          => ['required', 'string'],
            'receiver_legal_name'   => ['required', 'string', 'max:255'],
            'receiver_tax_regime'   => ['required', 'string', 'max:10'],
            'receiver_postal_code'  => ['required', 'string', 'size:5'],
            'cfdi_use'              => ['required', 'string', 'max:10'],

            // --- Payment ---
            'payment_form'          => ['required', 'string', 'max:5'],
            'payment_method'        => ['required', 'string', 'max:5'],
            'currency'              => ['nullable', 'string', 'max:5'],
            'exchange_rate'         => ['nullable', 'numeric', 'min:0.000001'],

            // --- Items ---
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.description'   => ['required', 'string', 'max:255'],
            'items.*.quantity'      => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'items.*.sat_product_code' => ['required', 'string', 'max:15'],
            'items.*.sat_unit_code' => ['required', 'string', 'max:10'],
            'items.*.unit_name'     => ['nullable', 'string', 'max:50'],
            'items.*.no_identificacion' => ['nullable', 'string', 'max:100'],
            'items.*.objeto_imp'    => ['required', 'string', 'max:5', 'in:01,02,03'],
            'items.*.concepto_tipo' => ['nullable', 'string', 'max:30'],
            'items.*.tax_type'      => ['nullable', 'string', 'max:5'],
            'items.*.tax_rate'      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.retained_tax_type'   => ['nullable', 'string', 'max:5'],
            'items.*.retained_tax_rate'   => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.retained_tax_amount' => ['nullable', 'numeric', 'min:0'],

            // --- Multi-retention array (replaces single retained_tax_* fields) ---
            'items.*.retentions'                => ['nullable', 'array'],
            'items.*.retentions.*.type'         => ['required_with:items.*.retentions', 'string', 'max:5', 'in:001,002'],
            'items.*.retentions.*.rate'         => ['required_with:items.*.retentions', 'numeric', 'min:0', 'max:1'],
            'items.*.retentions.*.amount'       => ['required_with:items.*.retentions', 'numeric', 'min:0'],

            // --- Optional relations ---
            'customer_id'           => ['nullable', 'integer', 'exists:customers,id'],
            'series'                => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_profile_id.required'     => 'Selecciona un perfil fiscal emisor.',
            'fiscal_profile_id.exists'       => 'El perfil fiscal seleccionado no existe.',
            'exportacion.required'           => 'El campo exportación es obligatorio.',
            'exportacion.in'                 => 'El valor de exportación no es válido.',
            'receiver_rfc.required'          => 'El campo RFC es obligatorio.',
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
            'items.*.objeto_imp.required'    => 'El objeto de impuesto es obligatorio.',
            'items.*.objeto_imp.in'          => 'El objeto de impuesto no es válido (01, 02 o 03).',
            'items.*.tax_rate.min'           => 'La tasa de impuesto no puede ser negativa.',
            'items.*.tax_rate.max'           => 'La tasa de impuesto no puede exceder 1 (100 %).',
            'items.*.discount_amount.min'    => 'El descuento no puede ser negativo.',
            'items.*.retained_tax_rate.min'  => 'La tasa de retención no puede ser negativa.',
            'items.*.retained_tax_rate.max'  => 'La tasa de retención no puede exceder 1 (100 %).',
            'items.*.retained_tax_amount.min'=> 'El importe de retención no puede ser negativo.',
            'items.*.retentions.*.type.required_with' => 'El tipo de retención es obligatorio.',
            'items.*.retentions.*.type.in'           => 'El tipo de retención debe ser 001 (ISR) o 002 (IVA).',
            'items.*.retentions.*.rate.required_with' => 'La tasa de retención es obligatoria.',
            'items.*.retentions.*.rate.min'          => 'La tasa de retención no puede ser negativa.',
            'items.*.retentions.*.rate.max'          => 'La tasa de retención no puede exceder 1 (100 %).',
            'items.*.retentions.*.amount.required_with' => 'El importe de retención es obligatorio.',
            'items.*.retentions.*.amount.min'        => 'El importe de retención no puede ser negativo.',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * SAT CFDI 4.0 rules enforced here:
     *  - PPD requires FormaPago = "99" (por definir).
     *  - RFC length depends on tax regime (12 = Persona Moral, 13 = Persona Física).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $method = $this->input('payment_method');
                $form   = $this->input('payment_form');

                // SAT rule: PPD → FormaPago must be "99" (por definir)
                if ($method === 'PPD' && $form !== '99') {
                    $validator->errors()->add(
                        'payment_form',
                        'Cuando el método de pago es PPD, la forma de pago debe ser "99" (por definir).'
                    );
                }

                // SAT rule: RFC length must match the tax regime type
                $rfc    = $this->input('receiver_rfc');
                $regime = $this->input('receiver_tax_regime');

                if (!$rfc) {
                    return;
                }

                $rfcLength = strlen($rfc);

                if ($regime) {
                    $expectedLength = $this->expectedRfcLengthForRegime($regime);

                    if ($expectedLength !== null && $rfcLength !== $expectedLength) {
                        $typeLabel = $expectedLength === 12 ? 'Persona Moral' : 'Persona Física';
                        $validator->errors()->add(
                            'receiver_rfc',
                            "El RFC debe tener {$expectedLength} caracteres para el régimen seleccionado ({$typeLabel}). Ingresaste {$rfcLength}."
                        );
                    }

                    return;
                }

                // No regime selected yet — give generic length guidance
                if ($rfcLength < 12) {
                    $validator->errors()->add(
                        'receiver_rfc',
                        'El RFC debe tener 12 caracteres (Persona Moral) o 13 (Persona Física). Ingresaste ' . $rfcLength . '.'
                    );
                } elseif ($rfcLength > 13) {
                    $validator->errors()->add(
                        'receiver_rfc',
                        'El RFC no puede tener más de 13 caracteres. Ingresaste ' . $rfcLength . '.'
                    );
                }
            },
        ];
    }

    /**
     * Determine the expected RFC length based on the SAT tax regime code.
     * Returns 12 for Persona Moral, 13 for Persona Física, or null if unknown.
     */
    private function expectedRfcLengthForRegime(string $regime): ?int
    {
        // Persona Moral — 12-character RFC
        $moralRegimes = ['601', '603', '610', '620', '622', '623', '624', '628'];

        // Persona Física — 13-character RFC
        $fisicaRegimes = ['605', '606', '607', '608', '609', '611', '612', '614', '615', '616', '621', '625', '626', '629'];

        if (in_array($regime, $moralRegimes, true)) {
            return 12;
        }

        if (in_array($regime, $fisicaRegimes, true)) {
            return 13;
        }

        return null;
    }
}
