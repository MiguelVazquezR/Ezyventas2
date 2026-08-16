<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the manual confirmation of a manual_review stamp reservation.
 *
 * uuid and cfdi_xml are optional: they are captured "if available". The
 * reservation can be confirmed relying on the last_pac_response data alone.
 */
class ConfirmManualReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // CheckSuperAdmin middleware handles access
    }

    public function rules(): array
    {
        return [
            'uuid'     => ['nullable', 'string', 'uuid'],
            'cfdi_xml' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'uuid.uuid' => 'El UUID debe tener un formato válido (8-4-4-4-12).',
        ];
    }
}
