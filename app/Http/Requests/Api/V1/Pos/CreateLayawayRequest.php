<?php

namespace App\Http\Requests\Api\V1\Pos;

/**
 * Layaway (apartado): the same sale with a mandatory expiration date, so the
 * stock stays reserved until the customer pays it off.
 */
class CreateLayawayRequest extends RegisterSaleRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'layaway_expiration_date' => ['required', 'date', 'after:today'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'layaway_expiration_date.required' => 'Indica la fecha límite del apartado.',
            'layaway_expiration_date.after' => 'La fecha límite del apartado debe ser posterior a hoy.',
        ]);
    }
}
