<?php

namespace App\Actions\ServiceOrders;

use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionStatus;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeServiceOrderStatusAction
{
    /**
     * Ejecuta el cambio de estatus y retorna un array con el resultado y mensaje.
     */
    public function execute(ServiceOrder $serviceOrder, ServiceOrderStatus $newStatus, User $user): array
    {
        $oldStatus = $serviceOrder->status;

        if ($oldStatus->value === $newStatus->value) {
            return ['success' => false, 'message' => 'El estatus ya es el seleccionado.'];
        }

        $message = 'Estatus de la orden actualizado correctamente.';
        $isReversion = false;

        if ($newStatus === ServiceOrderStatus::CANCELLED && $oldStatus !== ServiceOrderStatus::CANCELLED) {
            
            DB::transaction(function () use ($serviceOrder, $user) {
                $serviceOrder->load('items', 'transaction.customer', 'transaction.payments');
                
                $serviceOrder->restoreStock($user, "Cancelación de O.S. #{$serviceOrder->folio}");

                $customer = $serviceOrder->customer;
                $transaction = $serviceOrder->transaction;

                if ($customer && $transaction && !$transaction->isFullyPaid()) {
                    $customer->cancelDebt(
                        amount: $transaction->remaining_due, 
                        transactionId: $transaction->id, 
                        notes: "Crédito por cancelación de O.S. #{$serviceOrder->folio}"
                    );
                }

                if ($transaction) {
                    $transaction->update(['status' => TransactionStatus::CANCELLED]);
                }
            });
            
            $message = 'Estatus de la orden actualizado a cancelado y stock devuelto.';
        } else {
            $statusFlow = [
                ServiceOrderStatus::PENDING->value => 1,
                ServiceOrderStatus::IN_PROGRESS->value => 2,
                ServiceOrderStatus::WAITING_FOR_PARTS->value => 3,
                ServiceOrderStatus::FINISHED->value => 4,
                ServiceOrderStatus::DELIVERED->value => 5,
            ];

            if (isset($statusFlow[$oldStatus->value]) && isset($statusFlow[$newStatus->value])) {
                $isReversion = $statusFlow[$newStatus->value] < $statusFlow[$oldStatus->value];
            }

            if ($isReversion) {
                $message = 'Estatus de la orden revertido correctamente.';
            }
        }

        $serviceOrder->update(['status' => $newStatus->value]);

        activity()
            ->performedOn($serviceOrder)
            ->causedBy($user)
            ->event('status_changed')
            ->withProperties([
                'old_status' => $oldStatus->value, 
                'new_status' => $newStatus->value,
                'is_reversion' => $isReversion
            ])
            ->log("El estatus se " . ($isReversion ? 'revirtió' : 'cambió') . " de '{$oldStatus->value}' a '{$newStatus->value}'.");

        return ['success' => true, 'message' => $message];
    }
}