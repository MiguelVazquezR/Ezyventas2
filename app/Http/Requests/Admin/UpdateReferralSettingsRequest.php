<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReferralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->branch->subscription_id === 1;
    }

    public function rules(): array
    {
        return [
            'referred_discount_pct'         => ['required', 'numeric', 'min:0', 'max:100'],
            'referrer_reward_pct'           => ['required', 'numeric', 'min:0', 'max:100'],
            'referrer_ongoing_discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'referred_discount_pct.required'         => 'El porcentaje de descuento para el referido es obligatorio.',
            'referrer_reward_pct.required'           => 'El porcentaje de premio al referidor es obligatorio.',
            'referrer_ongoing_discount_pct.required' => 'El porcentaje de descuento continuo es obligatorio.',
        ];
    }
}
