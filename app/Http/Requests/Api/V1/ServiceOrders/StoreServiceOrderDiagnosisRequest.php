<?php

namespace App\Http\Requests\Api\V1\ServiceOrders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Technician diagnosis with its closing evidence photos (multipart).
 */
class StoreServiceOrderDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('services.orders.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'technician_diagnosis' => ['nullable', 'string', 'max:1000'],
            'closing_evidence_images' => ['nullable', 'array', 'max:5'],
            'closing_evidence_images.*' => ['image'],
        ];
    }

    public function messages(): array
    {
        return [
            'technician_diagnosis.max' => 'El diagnóstico no puede tener más de 1000 caracteres.',
            'closing_evidence_images.max' => 'Puedes adjuntar como máximo 5 fotos.',
            'closing_evidence_images.*.image' => 'Cada archivo debe ser una imagen.',
        ];
    }

    public function attributes(): array
    {
        return [
            'technician_diagnosis' => 'diagnóstico',
            'closing_evidence_images' => 'evidencias',
        ];
    }
}
