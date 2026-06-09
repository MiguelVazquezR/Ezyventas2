<?php

namespace App\Actions\Admin\Subscriptions;

use App\Enums\BillingPeriod;
use App\Models\PlanItem;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class CreateVersionWithPaymentAction
{
    /**
     * Crea una nueva versión y su pago asociado para una suscripción.
     */
    public function execute(Subscription $subscription, array $data): void
    {
        DB::transaction(function () use ($subscription, $data) {

            // 1. Crear la nueva versión
            $version = $subscription->versions()->create([
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
            ]);

            // 2. Crear items de módulos activos
            $modulePlanItems = PlanItem::where('type', 'module')->get()->keyBy('key');

            foreach ($data['modules'] as $key => $isActive) {
                if (!$isActive) continue;

                $planItem = $modulePlanItems->get($key);
                if (!$planItem) continue;

                $version->items()->create([
                    'item_key'       => $key,
                    'item_type'      => 'module',
                    'name'           => $planItem->name,
                    'quantity'       => 1,
                    'unit_price'     => $planItem->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                ]);
            }

            // 3. Crear items de límites
            $limitPlanItems = PlanItem::where('type', 'limit')->get()->keyBy('key');

            foreach ($data['limits'] as $key => $quantity) {
                $planItem = $limitPlanItems->get($key);
                if (!$planItem) continue;

                $version->items()->create([
                    'item_key'       => $key,
                    'item_type'      => 'limit',
                    'name'           => $planItem->name,
                    'quantity'       => $quantity,
                    'unit_price'     => $planItem->monthly_price,
                    'billing_period' => BillingPeriod::MONTHLY->value,
                ]);
            }

            // 4. Crear el pago asociado
            $version->payments()->create([
                'amount'         => $data['payment_amount'],
                'payment_method' => $data['payment_method'],
                'status'         => $data['payment_status'],
                'invoiced'       => false,
            ]);
        });
    }
}
