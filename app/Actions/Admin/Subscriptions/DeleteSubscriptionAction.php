<?php

namespace App\Actions\Admin\Subscriptions;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class DeleteSubscriptionAction
{
    /**
     * Elimina una suscripción y todos sus recursos relacionados en cascada.
     *
     * Orden de eliminación para respetar integridad referencial:
     * 1. Media (fiscal-documents)
     * 2. StampPurchases → FiscalProfiles
     * 3. BankAccounts
     * 4. StoreConfig
     * 5. SubscriptionVersion items → payments (proof_of_payment media) → versions
     * 6. Branches (cascade: users, products, services, cashRegisters, printTemplates, expenses, etc.)
     * 7. Settings (morph)
     * 8. La Subscription misma
     */
    public function execute(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {

            // 1. Limpiar media de la suscripción
            $subscription->clearMediaCollection('fiscal-documents');

            // 2. FiscalProfiles y sus StampPurchases
            $fiscalProfiles = $subscription->fiscalProfiles()->get();
            foreach ($fiscalProfiles as $profile) {
                $profile->stampPurchases()->delete();
                $profile->delete();
            }

            // 3. BankAccounts
            $subscription->bankAccounts()->delete();

            // 4. StoreConfig
            $subscription->storeConfig()->delete();

            // 5. Versions con sus items y pagos
            $versions = $subscription->versions()->get();
            foreach ($versions as $version) {
                // Eliminar pagos (y su media de comprobante)
                foreach ($version->payments as $payment) {
                    $payment->clearMediaCollection('proof_of_payment');
                    $payment->delete();
                }

                // Eliminar items de la versión
                $version->items()->delete();

                // Eliminar la versión
                $version->delete();
            }

            // 6. Branches (esto elimina en cascada: users, products, services, etc.)
            $branches = $subscription->branches()->get();
            foreach ($branches as $branch) {
                $branch->delete();
            }

            // 7. Settings (morph)
            $subscription->settings()->delete();

            // 8. Referral usage (si existe)
            if ($subscription->referralUsageAsReferred) {
                $subscription->referralUsageAsReferred->delete();
            }

            // 9. PrintTemplates
            $subscription->printTemplates()->delete();

            // 10. Eliminar la suscripción misma
            $subscription->delete();
        });
    }
}