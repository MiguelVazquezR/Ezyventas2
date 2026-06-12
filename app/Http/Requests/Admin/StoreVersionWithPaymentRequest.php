<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreVersionWithPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date'      => ['required', 'date'],
            'end_date'        => ['required', 'date', 'after_or_equal:start_date'],
            'payment_amount'  => ['required', 'numeric', 'min:0'],
            'payment_method'  => ['required', 'string', 'in:transfer,cash,card,other'],
            'payment_status'  => ['required', 'string', 'in:approved,pending,rejected'],
            'limits'          => ['required', 'array'],
            'limits.*'        => ['required', 'integer', 'min:-1'],
            'modules'         => ['required', 'array'],
            'modules.*'       => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
            'payment_amount.required' => 'El monto del pago es obligatorio.',
            'payment_amount.min'      => 'El monto del pago debe ser mayor o igual a 0.',
            'payment_method.in'       => 'El método de pago seleccionado no es válido.',
            'payment_status.in'       => 'El estado del pago seleccionado no es válido.',
            'limits.*.integer'        => 'El valor de los límites debe ser numérico.',
        ];
    }
}
