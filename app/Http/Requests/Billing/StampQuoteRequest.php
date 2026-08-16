<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class StampQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stamps.purchase');
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.required' => 'La cantidad es obligatoria.',
            'quantity.min'      => 'La cantidad mínima es 1 timbre.',
        ];
    }
}
