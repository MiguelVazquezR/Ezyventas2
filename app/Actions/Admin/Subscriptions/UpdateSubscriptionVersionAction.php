<?php

namespace App\Actions\Admin\Subscriptions;

use App\Models\SubscriptionVersion;
use App\Models\PlanItem;
use Illuminate\Support\Facades\DB;

class UpdateSubscriptionVersionAction
{
    /**
     * Ejecuta la lógica de negocio para actualizar una versión de suscripción.
     */
    public function execute(SubscriptionVersion $version, array $data): void
    {
        DB::transaction(function () use ($version, $data) {
            
            // 1. Actualizar las fechas base de la versión
            $version->update([
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
            ]);

            // 2. Extraer el periodo de facturación actual 
            // (Para heredar este valor a los ítems nuevos que inyectemos, si aplica)
            $firstItem = $version->items()->first();
            $billingPeriod = $firstItem ? $firstItem->billing_period : 'monthly'; 

            // 3. Traer el catálogo base de límites para tener sus nombres y precios por defecto
            $planItemsLimits = PlanItem::where('type', 'limit')->get()->keyBy('key');

            // 4. Sincronizar o inyectar las cantidades actualizadas
            foreach ($data['limits'] as $key => $quantity) {
                
                $planItem = $planItemsLimits->get($key);
                if (!$planItem) {
                    continue; // Seguridad: Si mandan un key que no existe, se ignora.
                }

                $existingItem = $version->items()->where('item_key', $key)->first();

                if ($existingItem) {
                    // Si ya tenía el límite, simplemente actualizamos la cantidad
                    $existingItem->update(['quantity' => $quantity]);
                } else {
                    // Si el cliente no tenía este límite registrado (ej. un módulo límite recién creado en el sistema), 
                    // lo inyectamos directamente a su versión actual.
                    $version->items()->create([
                        'item_key'       => $key,
                        'item_type'      => 'limit',
                        'name'           => $planItem->name,
                        'quantity'       => $quantity,
                        'unit_price'     => clone $planItem->monthly_price,
                        'billing_period' => clone $billingPeriod,
                    ]);
                }
            }

        });
    }
}