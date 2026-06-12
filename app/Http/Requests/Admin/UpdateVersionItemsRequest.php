<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVersionItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'limits'     => ['required', 'array'],
            'limits.*'   => ['required', 'integer', 'min:-1'],
            'modules'    => ['required', 'array'],
            'modules.*'  => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
            'limits.*.integer'        => 'El valor de los límites debe ser numérico.',
        ];
    }
}
