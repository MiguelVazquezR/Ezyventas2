<?php

namespace App\Actions\Subscription;

use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Expense;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

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
                
                // 1. Restaurar la fecha original si el pago fallido fue por un Upgrade
                $details = $lastPayment->payment_details ?? [];
                if (!empty($details['is_upgrade']) && !empty($details['original_end_date'])) {
                    $previousVersion = $subscription->versions()->where('id', '!=', $latestVersion->id)->latest('id')->first();
                    
                    if ($previousVersion) {
                        $previousVersion->update([
                            'end_date' => Carbon::parse($details['original_end_date'])
                        ]);
                    }
                }

                // 2. Borrar Gasto Pendiente asociado
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