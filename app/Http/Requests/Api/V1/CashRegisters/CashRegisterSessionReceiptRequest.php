<?php

namespace App\Http\Requests\Api\V1\CashRegisters;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reprinting the receipt of a shift (cut).
 *
 * Without `template_id` the server uses the cut template of the business
 * (`context_type = cash_register`) and, when it does not exist yet, a built-in
 * cut template, so the phone can always reprint a closed register.
 */
class CashRegisterSessionReceiptRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    public function authorize(): bool
    {
        return $this->canAnyPermission(['pos.access']);
    }

    public function rules(): array
    {
        return [
            'template_id' => ['nullable', 'integer', 'exists:print_templates,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'template_id.exists' => 'La plantilla seleccionada no existe.',
        ];
    }
}
