<?php

namespace App\Http\Requests\Api\V1\Account;

/**
 * General data of the subscription (owner only).
 */
class UpdateSubscriptionRequest extends SubscriptionRequest
{
    public function rules(): array
    {
        return [
            'commercial_name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'operating_hours' => ['nullable', 'array', 'size:7'],
            'operating_hours.*.day' => ['required', 'string'],
            'operating_hours.*.open' => ['required', 'boolean'],
            'operating_hours.*.from' => ['nullable', 'date_format:H:i'],
            'operating_hours.*.to' => ['nullable', 'date_format:H:i', 'after_or_equal:operating_hours.*.from'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'operating_hours.size' => 'El horario debe incluir los 7 días de la semana.',
            'operating_hours.*.to.after_or_equal' => 'La hora de cierre debe ser posterior a la de apertura.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'commercial_name' => 'nombre comercial',
            'business_name' => 'razón social',
            'contact_phone' => 'teléfono de contacto',
            'address' => 'dirección',
            'operating_hours' => 'horario de atención',
        ];
    }
}
