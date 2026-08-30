<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.edit');
    }

    public function rules(): array
    {
        return [
            // --- Emitter (emisor) ---
            'fiscal_profile_id'     => ['required', 'integer', 'exists:fiscal_profiles,id'],

            // --- Global CFDI 4.0 attributes ---
            'exportacion'           => ['required', 'string', 'max:5', 'in:01,02,03,04'],
            'tipo_comprobante'      => ['nullable', 'string', 'max:5', 'in:I,E,P,N,T'],

            // --- Fecha de emisión ---
            // Regla SAT: un CFDI no puede emitirse con una fecha de hace más
            // de 72 horas. El frontend restringe la selección; esto valida.
            'issued_at'             => ['nullable', 'date', 'after_or_equal:'.now()->subHours(72)->format('Y-m-d H:i:s')],

            // --- Receiver (receptor) ---
            'receiver_rfc'          => ['required', 'string'],
            'receiver_legal_name'   => ['required', 'string', 'max:255'],
            'receiver_tax_regime'   => ['required', 'string', 'max:10'],
            'receiver_postal_code'  => ['required', 'string', 'size:5'],
            'cfdi_use'              => ['required', 'string', 'max:10'],

            // --- Payment ---
            // Only Ingreso (I) and Egreso (E) carry FormaPago/MetodoPago in the
            // header per SAT Anexo 20. A CFDI de Pago (P), carta porte (T) and
            // nómina (N) do NOT — the payment details of a CFDI de pago live in
            // the Pago node (Complemento de Pago 2.0), not in the header.
            // `nullable` is required because ConvertEmptyStringsToNull turns the
            // frontend's "" into null before validation.
            'payment_form'          => [
                'nullable',
                Rule::requiredIf(fn () => in_array($this->input('tipo_comprobante', 'I'), ['I', 'E'], true)),
                'string',
                'max:5',
            ],
            'payment_method'        => [
                'nullable',
                Rule::requiredIf(fn () => in_array($this->input('tipo_comprobante', 'I'), ['I', 'E'], true)),
                'string',
                'max:5',
            ],

            // --- CFDI de pago (Complemento de Pago 2.0) — only for Tipo P ---
            // When the comprobante type is P (pago) the payment detail becomes
            // mandatory: reception date/time, real payment form, total amount
            // and at least one related PPD document with its partiality and
            // balances. `nullable` is required because ConvertEmptyStringsToNull
            // turns the frontend's "" into null before validation.
            'pago_fecha' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'date',
            ],
            'pago_forma' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'string',
                'max:5',
            ],
            'pago_moneda'            => ['nullable', 'string', 'max:5'],
            'pago_monto' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'numeric',
                'min:0',
            ],
            'pago_tipo_cambio' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P' && $this->input('pago_moneda') !== 'MXN'),
                'numeric',
                'min:0.000001',
            ],
            'pago_documentos' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'array',
                // `min:1` must only apply to a CFDI de Pago (P): Ingreso (I),
                // Egreso (E) and carta porte (T) send an empty array [] that
                // must not require related PPD documents.
                Rule::when($this->input('tipo_comprobante') === 'P', 'min:1'),
            ],
            'pago_documentos.*.uuid' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'string',
                'uuid',
            ],
            'pago_documentos.*.folio' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'string',
                'max:50',
            ],
            'pago_documentos.*.invoice_id'      => ['nullable', 'integer'],
            'pago_documentos.*.is_default'      => ['nullable', 'boolean'],
            'pago_documentos.*.num_parcialidad' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'integer',
                'min:1',
            ],
            'pago_documentos.*.imp_saldo_ant' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'numeric',
                'min:0',
            ],
            'pago_documentos.*.imp_pagado' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'numeric',
                'min:0',
            ],
            'pago_documentos.*.imp_saldo_insoluto' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'P'),
                'numeric',
                'min:0',
            ],

            // --- Nota de crédito (Tipo E) — CFDI relacionados ---
            // A credit note must relate at least one invoice (UUID). When the
            // comprobante is not E the frontend sends an empty array, so the
            // required/min rules only apply to Tipo E.
            'tipo_relacion'          => ['nullable', 'string', 'max:5'],
            'cfdi_relacionados'      => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'E'),
                'array',
                Rule::when($this->input('tipo_comprobante') === 'E', 'min:1'),
            ],
            'cfdi_relacionados.*'    => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo_comprobante') === 'E'),
                'string',
                'uuid',
            ],

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
            'items.*.itemable_id'   => ['nullable', 'integer'],
            'items.*.itemable_type' => ['nullable', 'string', 'in:product,service'],
            'items.*.objeto_imp'    => ['required', 'string', 'max:5', 'in:01,02,03'],
            'items.*.concepto_tipo' => ['nullable', 'string', 'max:30'],
            'items.*.tax_type'      => ['nullable', 'string', 'max:5'],
            'items.*.tax_rate'      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.retained_tax_type'   => ['nullable', 'string', 'max:5'],
            'items.*.retained_tax_rate'   => ['nullable', 'numeric', 'min:0', 'max:1'],
            'items.*.retained_tax_amount' => ['nullable', 'numeric', 'min:0'],

            // --- Multi-retention array ---
            'items.*.retentions'                => ['nullable', 'array'],
            'items.*.retentions.*.type'         => ['required_with:items.*.retentions', 'string', 'max:5', 'in:001,002'],
            'items.*.retentions.*.rate'         => ['required_with:items.*.retentions', 'numeric', 'min:0', 'max:1'],
            'items.*.retentions.*.amount'       => ['required_with:items.*.retentions', 'numeric', 'min:0'],

            // --- Linked POS sale (optional 1:1 relation) ---
            'transaction_id'        => [
                'nullable',
                'integer',
                Rule::exists('transactions', 'id')
                    ->where(fn ($query) => $query->where('branch_id', $this->user()->branch_id)),
            ],
            'prices_include_iva'    => ['nullable', 'boolean'],

            // --- Optional relations ---
            'customer_id'           => ['nullable', 'integer', 'exists:customers,id'],
            'series'                => ['nullable', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_profile_id.required'     => 'Selecciona un perfil fiscal emisor.',
            'fiscal_profile_id.exists'       => 'El emisor fiscal seleccionado no existe.',
            'issued_at.after_or_equal'       => 'La fecha de emisión no puede ser de hace más de 72 horas.',
            'transaction_id.exists'          => 'La venta seleccionada no existe o no pertenece a esta sucursal.',
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
            'cfdi_relacionados.required'     => 'Agrega al menos un UUID de la factura a relacionar.',
            'cfdi_relacionados.min'          => 'Agrega al menos un UUID de la factura a relacionar.',
            'cfdi_relacionados.*.required'   => 'El UUID de la factura relacionada es obligatorio.',
            'cfdi_relacionados.*.uuid'       => 'El UUID de la factura relacionada no es válido. Debe tener el formato xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.',
            'pago_fecha.required'            => 'La fecha y hora de recepción del pago es obligatoria.',
            'pago_forma.required'            => 'La forma de pago real es obligatoria.',
            'pago_monto.required'            => 'El monto total del pago es obligatorio.',
            'pago_tipo_cambio.required'      => 'El tipo de cambio del pago es obligatorio para moneda extranjera.',
            'pago_documentos.required'       => 'Agrega al menos un documento relacionado (factura PPD).',
            'pago_documentos.min'            => 'Agrega al menos un documento relacionado (factura PPD).',
            'pago_documentos.*.uuid.required'            => 'El UUID de la factura es obligatorio.',
            'pago_documentos.*.uuid.uuid'                => 'El UUID de la factura no es válido. Debe tener el formato xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.',
            'pago_documentos.*.folio.required'           => 'El folio de la factura timbrada es obligatorio.',
            'pago_documentos.*.num_parcialidad.required' => 'El número de parcialidad es obligatorio.',
            'pago_documentos.*.imp_saldo_ant.required'   => 'El saldo anterior es obligatorio.',
            'pago_documentos.*.imp_pagado.required'      => 'El importe pagado es obligatorio.',
            'pago_documentos.*.imp_saldo_insoluto.required' => 'El saldo insoluto es obligatorio.',
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
            function (\Illuminate\Validation\Validator $validator) {
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

        if (in_array($regime, $moralRegimes, true)) {
            return 12;
        }

        // Persona Física — 13-character RFC (most regimes are física)
        $fisicaRegimes = ['605', '606', '607', '608', '609', '611', '612', '614', '615', '616', '621', '625', '626'];

        if (in_array($regime, $fisicaRegimes, true)) {
            return 13;
        }

        return null;
    }
}
