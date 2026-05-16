<?php

namespace App\Actions\Service;

use App\Models\Service;
use App\Models\User;
use App\Traits\OptimizeMediaLocal;
use Illuminate\Support\Facades\DB;

class StoreServiceAction
{
    use OptimizeMediaLocal;

    public function execute(array $data, User $user, $imageFile = null): Service
    {
        return DB::transaction(function () use ($data, $user, $imageFile) {
            
            $slug = Service::generateUniqueSlug($data['name'], $user->branch->subscription_id);

            $serviceData = collect($data)->except(['has_variants', 'variants', 'image', 'branch_ids'])->toArray();
            $serviceData['branch_id'] = $user->branch_id; 
            $serviceData['slug'] = $slug;

            if (!empty($data['has_variants'])) {
                $serviceData['base_price'] = 0;
                $serviceData['duration_estimate'] = null;
            }

            $service = Service::create($serviceData);

            $branches = !empty($data['branch_ids']) ? $data['branch_ids'] : [$user->branch_id];
            $service->branches()->sync($branches);

            if (!empty($data['has_variants']) && !empty($data['variants'])) {
                $service->syncVariants($data['variants']);
            }

            if ($imageFile) {
                $mediaItem = $service->addMedia($imageFile)->toMediaCollection('service-image');
                $this->optimizeMediaLocal($mediaItem);
            }

            return $service;
        });
    }
}