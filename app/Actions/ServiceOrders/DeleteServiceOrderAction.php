<?php

namespace App\Actions\ServiceOrders;

use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a service order together with the sale linked to it.
 *
 * The mobile app asks for an explicit confirmation before calling this
 * ("Esta acción no se puede deshacer.").
 */
class DeleteServiceOrderAction
{
    public function execute(ServiceOrder $serviceOrder): void
    {
        DB::transaction(function () use ($serviceOrder) {
            $serviceOrder->transaction()->delete();
            $serviceOrder->delete();
        });
    }
}
