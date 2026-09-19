<?php

namespace App\Actions\ServiceOrders;

use App\Models\ServiceOrder;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Saves the technician diagnosis and appends the closing evidence photos.
 *
 * Used by the web order screen and by the mobile API, so both keep the same
 * behaviour: the diagnosis is only overwritten when it is sent.
 */
class SaveServiceOrderDiagnosisAction
{
    use OptimizeMediaLocal;

    /**
     * @param  array<string, mixed>  $attributes  attributes to update (e.g. technician_diagnosis)
     * @param  array<int, UploadedFile>  $images  closing evidence photos
     */
    public function execute(ServiceOrder $serviceOrder, array $attributes = [], array $images = []): ServiceOrder
    {
        DB::transaction(function () use ($serviceOrder, $attributes, $images) {
            if ($attributes !== []) {
                $serviceOrder->update($attributes);
            }

            foreach ($images as $image) {
                $media = $serviceOrder->addMedia($image)->toMediaCollection('closing-service-order-evidence');
                $this->optimizeMediaLocal($media);
            }
        });

        return $serviceOrder->refresh();
    }
}
