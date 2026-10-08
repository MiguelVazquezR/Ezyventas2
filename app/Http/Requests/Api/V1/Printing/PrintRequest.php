<?php

namespace App\Http\Requests\Api\V1\Printing;

use App\Http\Requests\Api\V1\Concerns\AuthorizesAnyPermission;
use App\Services\Printing\PrintDataSourceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base of the printing requests.
 *
 * Printing is used from the POS screen and from the service orders screen, so
 * any operational role may use it; the data source is always scoped to the
 * subscription of the user (see PrintDataSourceResolver).
 */
abstract class PrintRequest extends FormRequest
{
    use AuthorizesAnyPermission;

    /**
     * @var array<int, string>
     */
    protected const OPERATIONAL_PERMISSIONS = [
        'pos.access',
        'transactions.access',
        'services.orders.access',
    ];

    public function authorize(): bool
    {
        return $this->canAnyPermission(self::OPERATIONAL_PERMISSIONS);
    }

    /**
     * Validation rule for `data_source_type`.
     */
    protected function sourceTypeRule(): mixed
    {
        return Rule::in(PrintDataSourceResolver::SOURCE_TYPES);
    }

    public function messages(): array
    {
        return [
            'template_id.required' => 'Selecciona la plantilla de impresión.',
            'template_id.exists' => 'La plantilla seleccionada no existe.',
            'data_source_type.required' => 'Indica qué documento vas a imprimir.',
            'data_source_type.in' => 'El tipo de documento no es válido.',
            'data_source_id.required' => 'Indica el documento que vas a imprimir.',
            'data_source_id.integer' => 'El documento seleccionado no es válido.',
        ];
    }
}
