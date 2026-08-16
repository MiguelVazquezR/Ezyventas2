<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStampPricingTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // CheckSuperAdmin middleware handles access
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
     * Validate that the updated tier's range does not overlap with any other active tiers.
     */
    private function noOverlapRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! $this->boolean('is_active', true)) {
                return;
            }

            $maxQty = $this->input('max_quantity');
            /** @var \App\Models\Billing\StampPricingTier $tier */
            $tier = $this->route('tier');

            $overlapping = \App\Models\Billing\StampPricingTier::query()
                ->where('is_active', true)
                ->where('id', '!=', $tier->id)
                ->where(function ($query) use ($value, $maxQty) {
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
