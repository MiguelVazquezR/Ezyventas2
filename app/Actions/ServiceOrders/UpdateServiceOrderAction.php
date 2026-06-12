<?php

namespace App\Actions\ServiceOrders;

use App\Enums\CustomerBalanceMovementType;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;

class UpdateServiceOrderAction
{
    use OptimizeMediaLocal;

    public function execute(ServiceOrder $serviceOrder, array $data, User $user, ?array $evidenceImages = [], ?array $deletedMediaIds = []): ServiceOrder
    {
        return DB::transaction(function () use ($serviceOrder, $data, $user, $evidenceImages, $deletedMediaIds) {
            
            // 1. Sincronizar items y calcular diferencia neta de stock (solo altera lo que cambió)
            $itemsChanged = $this->syncItemsAndStock($serviceOrder, $data['items'] ?? [], $user);

            // 2. Actualizar los datos principales de la orden
            $serviceOrder->update($data);

            if ($itemsChanged) {
                activity()
                    ->performedOn($serviceOrder)
                    ->causedBy($user)
                    ->event('updated')
                    ->log('Se actualizaron los conceptos (refacciones/servicios) de la orden.');
            }

            // 3. Actualizar transacción y saldos del cliente
            $serviceOrder->load('transaction');
            if ($serviceOrder->transaction) {
                $customer = $serviceOrder->customer;
                $oldTotal = $serviceOrder->transaction->total;
                $newTotal = $data['final_total'];
                $totalDifference = $newTotal - $oldTotal;

                $serviceOrder->transaction->update([
                    'subtotal' => $data['subtotal'],
                    'total_discount' => $data['discount_amount'],
                ]);

                if ($customer && $totalDifference != 0) {
                    if ($totalDifference > 0) {
                        $customer->addDebt(
                            amount: $totalDifference,
                            debtType: CustomerBalanceMovementType::CREDIT_SALE,
                            transactionId: $serviceOrder->transaction->id,
                            notes: "Ajuste (+): Incremento de cargo en O.S. #{$serviceOrder->folio}"
                        );
                    } else {
                        $customer->cancelDebt(
                            amount: abs($totalDifference),
                            transactionId: $serviceOrder->transaction->id,
                            notes: "Ajuste (-): Reducción de cargo en O.S. #{$serviceOrder->folio}"
                        );
                    }
                }
            }

            // 4. Gestión de medios e imágenes (CORRECCIÓN APLICADA AQUÍ)
            if (!empty($deletedMediaIds)) {
                // Instanciamos los modelos Media primero para que el evento 'deleting' de Spatie
                // se dispare y elimine físicamente los archivos del disco (evitando huérfanos).
                $mediaToDelete = $serviceOrder->media()->whereIn('id', $deletedMediaIds)->get();
                foreach ($mediaToDelete as $media) {
                    $media->delete(); 
                }
            }

            if (!empty($evidenceImages)) {
                foreach ($evidenceImages as $file) {
                    $this->optimizeMediaLocal($serviceOrder->addMedia($file)->toMediaCollection('initial-service-order-evidence'));
                }
            }

            return $serviceOrder;
        });
    }

    /**
     * Sincroniza los items de la orden y calcula la diferencia neta de stock
     * para realizar un único movimiento de ajuste (o ninguno si no hay cambios de cantidad).
     */
    private function syncItemsAndStock(ServiceOrder $serviceOrder, array $newItemsData, User $user): bool
    {
        $oldItems = $serviceOrder->items()->get();
        $itemsChanged = false;

        // 1. Agrupar cantidades actuales (viejas)
        $oldQuantities = [];
        foreach ($oldItems as $item) {
            if ($item->itemable_id && $item->itemable_type) {
                $key = $item->itemable_type . '::' . $item->itemable_id;
                $oldQuantities[$key] = ($oldQuantities[$key] ?? 0) + $item->quantity;
            }
        }

        // 2. Agrupar cantidades nuevas
        $newQuantities = [];
        foreach ($newItemsData as $item) {
            if (!empty($item['itemable_id']) && !empty($item['itemable_type'])) {
                $key = $item['itemable_type'] . '::' . $item['itemable_id'];
                $newQuantities[$key] = ($newQuantities[$key] ?? 0) + $item['quantity'];
            }
        }

        // 3. Comparar diferencias de stock
        $allKeys = array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities)));
        
        foreach ($allKeys as $key) {
            $oldQty = $oldQuantities[$key] ?? 0;
            $newQty = $newQuantities[$key] ?? 0;
            $diff = $newQty - $oldQty;

            if ($diff != 0) {
                $itemsChanged = true;
                list($type, $id) = explode('::', $key);
                $model = $type::find($id);

                if ($model) {
                    if ($diff > 0) {
                        // Necesita MÁS stock del que ya tenía
                        if (method_exists($model, 'deductStock')) {
                            $model->deductStock($serviceOrder->branch_id, $diff, $user, "Ajuste por refacciones agregadas en O.S. #{$serviceOrder->folio}");
                        }
                    } else {
                        // Necesita MENOS stock del que tenía (le sobra, se devuelve al inventario)
                        if (method_exists($model, 'restock')) {
                            $model->restock($serviceOrder->branch_id, abs($diff), $user, "Ajuste por refacciones devueltas en O.S. #{$serviceOrder->folio}");
                        }
                    }
                }
            }
        }

        // 4. Eliminamos y recreamos los pivotes (esto no mueve stock, solo actualiza la orden visualmente)
        $serviceOrder->items()->delete();
        foreach ($newItemsData as $itemData) {
            if (isset($itemData['itemable_id']) && $itemData['itemable_id'] == 0) {
                unset($itemData['itemable_id']);
            }
            $serviceOrder->items()->create($itemData);
        }

        // Verificamos si cambió algo más (precio, descripción o un servicio sin control de stock)
        // Como siempre reconstruimos la lista, si los tamaños no coinciden o llegó hasta aquí, 
        // asumimos que los items fueron tocados.
        if (!$itemsChanged && (count($oldItems) !== count($newItemsData))) {
            $itemsChanged = true;
        } elseif (!$itemsChanged && count($oldItems) > 0) {
            // Forzar true en caso de cambio de precios o texto descriptivo.
            $itemsChanged = true; 
        }

        return $itemsChanged;
    }
}