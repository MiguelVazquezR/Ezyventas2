<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionVersionRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado para hacer esta petición.
     */
    public function authorize(): bool
    {
        // La autorización ya se maneja mediante el middleware 'CheckSuperAdmin' en las rutas
        return true;
    }

    /**
     * Reglas de validación aplicadas a la petición.
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            // Aseguramos que la fecha final nunca sea menor a la de inicio
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            
            // Validamos que el objeto de límites venga correctamente
            'limits'     => ['required', 'array'],
            // Cada límite debe ser un entero y permitir al menos -1 (Ilimitado)
            'limits.*'   => ['required', 'integer', 'min:-1'], 
        ];
    }

    /**
     * Mensajes personalizados (opcional, si no usas los de lang/es)
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
            'limits.*.integer' => 'El valor de los límites debe ser numérico.',
        ];
    }
}