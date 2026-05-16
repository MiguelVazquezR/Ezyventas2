<?php

namespace App\Actions\Admin\PlanItems;

use App\Models\PlanItem;
use App\Enums\PlanItemType;

class CreatePlanItemAction
{
    /**
     * Ejecuta la lógica de negocio para crear un ítem.
     */
    public function execute(array $data): PlanItem
    {
        // Regla de negocio backend (Defensa en profundidad): 
        // Aunque Vue limpia la data, nos aseguramos que la BD quede impecable.
        $data['meta'] = $this->sanitizeMeta($data['type'], $data['meta'] ?? []);

        return PlanItem::create($data);
    }

    /**
     * Limpia los metadatos irrelevantes según el tipo.
     */
    protected function sanitizeMeta(string $type, array $meta): array
    {
        // Si el tipo es enum, obtenemos su valor. Si es string, lo usamos directo.
        $typeValue = $type instanceof PlanItemType ? $type->value : $type;

        if ($typeValue === PlanItemType::MODULE->value) {
            unset($meta['quantity']);
        } else {
            unset($meta['icon']);
        }

        return $meta;
    }
}