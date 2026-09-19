<?php

namespace App\Http\Requests\Api\V1\Printing;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use Illuminate\Validation\Rule;

/**
 * Available print templates of the business (the app caches them to be able to
 * print the same documents later).
 */
class PrintTemplatesRequest extends PrintRequest
{
    public function rules(): array
    {
        return [
            'context' => ['nullable', Rule::in(array_map(fn (TemplateContextType $type) => $type->value, TemplateContextType::cases()))],
            'type' => ['nullable', Rule::in(array_map(fn (TemplateType $type) => $type->value, TemplateType::cases()))],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'context.in' => 'El contexto de impresión no es válido.',
            'type.in' => 'El tipo de plantilla no es válido.',
        ]);
    }
}
