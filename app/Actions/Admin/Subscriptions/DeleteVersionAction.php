<?php

namespace App\Actions\Admin\Subscriptions;

use App\Models\SubscriptionVersion;
use Illuminate\Support\Facades\DB;

class DeleteVersionAction
{
    /**
     * Elimina una versión de suscripción junto con sus items, pagos y archivos asociados.
     */
    public function execute(SubscriptionVersion $version): void
    {
        DB::transaction(function () use ($version) {
            // 1. Eliminar pagos asociados (y sus archivos de comprobante via media-library)
            foreach ($version->payments as $payment) {
                $payment->clearMediaCollection('proof_of_payment');
                $payment->delete();
            }

            // 2. Eliminar items de la versión
            $version->items()->delete();

            // 3. Eliminar la versión
            $version->delete();
        });
    }
}
