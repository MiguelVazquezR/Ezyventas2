<?php

namespace App\Actions\Subscription;

use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Expense;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Exception;

class RevertFailedSubscriptionAction
{
    /**
     * Revierte una versión rechazada de la suscripción y elimina sus gastos vinculados.
     */
    public function execute(Subscription $subscription): bool
    {
        $latestVersion = $subscription->versions()->latest('id')->first();

        if (!$latestVersion) {
            return false;
        }

        $lastPayment = $latestVersion->payments()->latest('id')->first();

        if ($lastPayment && $lastPayment->status === SubscriptionPaymentStatus::REJECTED) {
            DB::transaction(function () use ($latestVersion, $lastPayment, $subscription) {
                // Borrar Gasto Pendiente asociado
                Expense::where('status', ExpenseStatus::PENDING)
                    ->where('amount', $lastPayment->amount)
                    ->where('description', 'like', 'Pago de suscripción%')
                    ->whereHas('branch.subscription', fn($q) => $q->where('id', $subscription->id))
                    ->latest('created_at')
                    ->first()
                    ?->delete();

                // Borrar la versión (pagos e items caen por cascade)
                $latestVersion->delete();
            });

            return true;
        }

        return false;
    }
}