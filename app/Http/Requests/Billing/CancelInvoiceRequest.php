<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel invoices');
    }

    public function rules(): array
    {
        return [
            'cancellation_reason' => [
                'required',
                'string',
                Rule::in(['01', '02', '03', '04']),
            ],
            'substitution_uuid' => [
                'required_if:cancellation_reason,01',
                'nullable',
                'string',
                'uuid',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cancellation_reason.required' => 'El motivo de cancelación es obligatorio.',
            'cancellation_reason.in'       => 'El motivo de cancelación no es válido.',
            'substitution_uuid.required_if' => 'El UUID de sustitución es obligatorio cuando el motivo es "01 — Comprobante emitido con errores con relación".',
            'substitution_uuid.uuid'       => 'El UUID de sustitución no tiene un formato válido.',
        ];
    }
}
