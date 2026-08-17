<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('invoices.cancel');
    }

    public function rules(): array
    {
        $subscriptionId = $this->user()?->branch?->subscription_id;

        return [
            'fiscal_profile_id' => [
                'required',
                'integer',
                Rule::exists('fiscal_profiles', 'id')->where('subscription_id', $subscriptionId),
            ],
            'uuid' => [
                'required',
                'string',
                'uuid',
            ],
            'action' => [
                'required',
                'string',
                Rule::in(['Aceptacion', 'Rechazo']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'fiscal_profile_id.required' => 'Selecciona el RFC receptor.',
            'fiscal_profile_id.integer'  => 'El RFC receptor no es válido.',
            'fiscal_profile_id.exists'   => 'El perfil fiscal seleccionado no pertenece a tu cuenta.',
            'uuid.required'              => 'El UUID de la factura recibida es obligatorio.',
            'uuid.uuid'                  => 'El UUID no tiene un formato válido.',
            'action.required'            => 'Selecciona si aceptas o rechazas la cancelación.',
            'action.in'                  => 'La acción seleccionada no es válida.',
        ];
    }
}
