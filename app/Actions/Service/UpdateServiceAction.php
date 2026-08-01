<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\User;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;

class UpdateServiceAction
{
    use OptimizeMediaLocal;

    public function execute(Service $service, array $data, User $user, $imageFile = null): Service
    {
        return DB::transaction(function () use ($service, $data, $user, $imageFile) {
            
            if ($service->name !== $data['name']) {
                $data['slug'] = Service::generateUniqueSlug($data['name'], $user->branch->subscription_id, $service->id);
            }

            $serviceData = collect($data)->except(['has_variants', 'variants', 'image', 'branch_ids'])->toArray();

            if (!empty($data['has_variants'])) {
                $serviceData['base_price'] = 0;
                $serviceData['duration_estimate'] = null;
            } else {
                // Fallback: si no hay variantes y el precio es null, usar 0 para evitar error de integridad
                $serviceData['base_price'] = $data['base_price'] ?? 0;
            }

            $service->update($serviceData);

            if (!empty($data['branch_ids'])) {
                $service->branches()->sync($data['branch_ids']);
            }

            if (!empty($data['has_variants'])) {
                $service->syncVariants($data['variants'] ?? []);
            } else {
                $service->variants()->delete();
            }

            if ($imageFile) {
                $service->clearMediaCollection('service-image');
                $mediaItem = $service->addMedia($imageFile)->toMediaCollection('service-image');
                $this->optimizeMediaLocal($mediaItem);
            }

            return $service;
        });
    }
}