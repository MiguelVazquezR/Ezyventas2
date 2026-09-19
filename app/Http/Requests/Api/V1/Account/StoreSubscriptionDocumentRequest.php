<?php

namespace App\Http\Requests\Api\V1\Account;

/**
 * Fiscal document of the business (constancia de situación fiscal).
 */
class StoreSubscriptionDocumentRequest extends SubscriptionRequest
{
    public function rules(): array
    {
        return [
            'fiscal_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'fiscal_document.required' => 'Adjunta tu constancia de situación fiscal.',
            'fiscal_document.mimes' => 'El documento debe ser un PDF o una imagen.',
            'fiscal_document.max' => 'El documento debe pesar menos de 2 MB.',
        ]);
    }

    public function attributes(): array
    {
        return ['fiscal_document' => 'documento fiscal'];
    }
}
