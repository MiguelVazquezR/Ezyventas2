<?php

namespace App\Actions\Admin\PlanItems;

use App\Models\PlanItem;
use App\Enums\PlanItemType;

class UpdatePlanItemAction
{
    /**
     * Ejecuta la lógica de negocio para actualizar un ítem.
     */
    public function execute(PlanItem $planItem, array $data): PlanItem
    {
        // Evitamos que inyecten un cambio de Key malicioso
        unset($data['key']);

        $data['meta'] = $this->sanitizeMeta($data['type'], $data['meta'] ?? []);

        $planItem->update($data);

        return $planItem;
    }

    protected function sanitizeMeta(string $type, array $meta): array
    {
        $typeValue = $type instanceof PlanItemType ? $type->value : $type;

        if ($typeValue === PlanItemType::MODULE->value) {
            unset($meta['quantity']);
        } else {
            unset($meta['icon']);
        }

        return $meta;
    }
}