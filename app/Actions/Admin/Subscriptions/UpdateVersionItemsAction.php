<?php

namespace App\Actions\Admin\Subscriptions;

use App\Enums\BillingPeriod;
use App\Models\PlanItem;
use App\Models\SubscriptionVersion;
use Illuminate\Support\Facades\DB;

class UpdateVersionItemsAction
{
    /**
     * Actualiza las fechas, módulos y límites de una versión de suscripción.
     */
    public function execute(SubscriptionVersion $version, array $data): void
    {
        DB::transaction(function () use ($version, $data) {

            // 1. Actualizar fechas
            $version->update([
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
            ]);

            // 2. Obtener el billing period actual para heredarlo
            $firstItem = $version->items()->first();
            $billingPeriod = $firstItem ? $firstItem->billing_period : BillingPeriod::MONTHLY->value;

            // 3. Sincronizar módulos
            $modulePlanItems = PlanItem::where('type', 'module')->get()->keyBy('key');

            foreach ($data['modules'] as $key => $isActive) {
                $planItem = $modulePlanItems->get($key);
                if (!$planItem) continue;

                $existingItem = $version->items()->where('item_key', $key)->first();

                if ($isActive && !$existingItem) {
                    // Activar: crear el item si no existe
                    $version->items()->create([
                        'item_key'       => $key,
                        'item_type'      => 'module',
                        'name'           => $planItem->name,
                        'quantity'       => 1,
                        'unit_price'     => $planItem->monthly_price,
                        'billing_period' => $billingPeriod,
                    ]);
                } elseif (!$isActive && $existingItem) {
                    // Desactivar: eliminar el item
                    $existingItem->delete();
                }
            }

            // 4. Sincronizar límites
            $limitPlanItems = PlanItem::where('type', 'limit')->get()->keyBy('key');

            foreach ($data['limits'] as $key => $quantity) {
                $planItem = $limitPlanItems->get($key);
                if (!$planItem) continue;

                $existingItem = $version->items()->where('item_key', $key)->first();

                if ($existingItem) {
                    $existingItem->update(['quantity' => $quantity]);
                } else {
                    $version->items()->create([
                        'item_key'       => $key,
                        'item_type'      => 'limit',
                        'name'           => $planItem->name,
                        'quantity'       => $quantity,
                        'unit_price'     => $planItem->monthly_price,
                        'billing_period' => $billingPeriod,
                    ]);
                }
            }
        });
    }
}
