<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTutorialVideoRequest extends FormRequest
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
            'module'      => ['required', 'string', Rule::in(array_keys(config('tutorials.modules')))],
            'section'     => ['required', 'string', 'max:120'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'duration'    => ['nullable', 'string', 'max:10'],
            'source_type' => ['required', Rule::in(['link', 'file'])],
            'url'         => ['nullable', 'required_if:source_type,link', 'url', 'max:500'],
            'file'        => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('source_type') === 'file'),
                'file',
                'mimetypes:video/mp4,video/webm,video/quicktime',
                'max:' . (int) config('tutorials.max_upload_kb'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'module.in'            => 'Selecciona un módulo válido.',
            'section.required'     => 'Indica la sección donde aparecerá el video.',
            'title.required'       => 'Escribe el título del video.',
            'source_type.required' => 'Selecciona cómo se cargará el video.',
            'url.required_if'      => 'Pega el enlace del video.',
            'url.url'              => 'El enlace no es válido.',
            'file.required_if'     => 'Selecciona el archivo de video.',
            'file.mimetypes'       => 'El archivo debe ser un video (MP4, WebM o MOV).',
            'file.max'             => 'El video supera el límite de subida permitido por el servidor.',
        ];
    }
}
