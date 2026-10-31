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
    /**
     * Normalizes `context` to an array.
     *
     * The web asks for several contexts at once (the POS needs `pos` + `general`,
     * the sale detail `transaction` + `general`), so the endpoint accepts one
     * value, a comma separated list (`context=pos,general`) or the array form
     * (`context[]=pos&context[]=general`) without changing what it already
     * answered for a single context.
     */
    protected function prepareForValidation(): void
    {
        $context = $this->input('context');

        if ($context === null || $context === '') {
            return;
        }

        $contexts = is_array($context) ? $context : explode(',', (string) $context);

        $this->merge([
            'context' => array_values(array_filter(
                array_map(fn ($value) => is_string($value) ? trim($value) : $value, $contexts),
                fn ($value) => $value !== null && $value !== ''
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'context' => ['nullable', 'array'],
            'context.*' => [
                'string',
                Rule::in(array_map(fn (TemplateContextType $type) => $type->value, TemplateContextType::cases())),
            ],
            'type' => ['nullable', Rule::in(array_map(fn (TemplateType $type) => $type->value, TemplateType::cases()))],
        ];
    }

    /**
     * Contexts the caller asked for (empty means every context of the business).
     *
     * @return array<int, string>
     */
    public function contexts(): array
    {
        return $this->validated('context') ?? [];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'context.array' => 'El contexto de impresión no es válido.',
            'context.in' => 'El contexto de impresión no es válido.',
            'context.*.in' => 'El contexto de impresión no es válido.',
            'type.in' => 'El tipo de plantilla no es válido.',
        ]);
    }
}
