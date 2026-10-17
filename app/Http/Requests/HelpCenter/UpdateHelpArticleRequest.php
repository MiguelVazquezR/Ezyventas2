<?php

namespace App\Http\Requests\HelpCenter;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHelpArticleRequest extends FormRequest
{
    /**
     * The admin route group is already protected by the CheckSuperAdmin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module'       => ['required', 'string', Rule::in(array_keys(config('tutorials.modules')))],
            'title'        => ['required', 'string', 'max:255'],
            'excerpt'      => ['nullable', 'string', 'max:500'],
            'content'      => ['required', 'string'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * Normalize an empty summary to null so the column stays clean.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('excerpt') && trim((string) $this->input('excerpt')) === '') {
            $this->merge(['excerpt' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'module.in'        => 'Selecciona un módulo válido.',
            'title.required'   => 'Escribe el título de la guía.',
            'content.required' => 'Escribe el contenido de la guía.',
        ];
    }
}
