<?php

namespace App\Http\Requests\Suggestions;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'    => ['required', 'string', 'in:feature,bug,improvement,other'],
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'priority'    => ['nullable', 'string', 'in:low,medium,high'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required'    => 'Selecciona una categoría para tu sugerencia.',
            'category.in'          => 'La categoría debe ser: feature, bug, improvement u other.',
            'title.required'       => 'Escribe un título breve para tu sugerencia.',
            'title.max'            => 'El título no debe exceder los 200 caracteres.',
            'description.required' => 'Describe tu sugerencia o comentario.',
            'description.max'      => 'La descripción no debe exceder los 5000 caracteres.',
            'priority.in'          => 'La prioridad debe ser: low, medium o high.',
        ];
    }
}