<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStampPricingTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'min_quantity' => [
                'required',
                'integer',
                'min:1',
                $this->noOverlapRule(),
            ],
            'max_quantity' => [
                'nullable',
                'integer',
                'gt:min_quantity',
            ],
            'unit_price' => [
                'required',
                'numeric',
                'min:0.01',
                'max:9999.9999',
            ],
            'label' => [
                'nullable',
                'string',
                'max:100',
            ],
            'is_active' => [
                'boolean',
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'min_quantity.required'  => 'La cantidad mínima es obligatoria.',
            'min_quantity.integer'   => 'La cantidad mínima debe ser un número entero.',
            'min_quantity.min'       => 'La cantidad mínima debe ser al menos 1.',
            'max_quantity.integer'   => 'La cantidad máxima debe ser un número entero.',
            'max_quantity.gt'        => 'La cantidad máxima debe ser mayor que la mínima.',
            'unit_price.required'    => 'El precio unitario es obligatorio.',
            'unit_price.numeric'     => 'El precio unitario debe ser un número.',
            'unit_price.min'         => 'El precio unitario debe ser mayor a 0.',
            'unit_price.max'         => 'El precio unitario es demasiado alto.',
        ];
    }

    /**
     * Validate that the new tier's range does not overlap with any existing active tiers.
     */
    private function noOverlapRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            // Skip overlap check if the tier is being set as inactive
            if (! $this->boolean('is_active', true)) {
                return;
            }

            $maxQty = $this->input('max_quantity');

            $overlapping = \App\Models\Billing\StampPricingTier::query()
                ->where('is_active', true)
                ->where(function ($query) use ($value, $maxQty) {
                    // New tier [min, max] overlaps with existing [db_min, db_max] if:
                    // new_min <= db_max AND (new_max >= db_min OR new_max IS NULL)
                    $query->where('min_quantity', '<=', $maxQty ?: 999999999)
                        ->where(function ($q) use ($value) {
                            $q->where('max_quantity', '>=', $value)
                              ->orWhereNull('max_quantity');
                        });
                })
                ->exists();

            if ($overlapping) {
                $fail('El rango de cantidades se solapa con otro tramo activo. Ajusta los valores mínimo y máximo.');
            }
        };
    }
}
