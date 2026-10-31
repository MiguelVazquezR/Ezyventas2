<?php

namespace App\Services\Catalog;

use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model of the services catalog of a branch, used by the mobile API to
 * build service order items. Mirrors the query of the web order form.
 */
class ServiceCatalogService
{
    /**
     * @param  array{search?: string|null, category_id?: int|null, updated_since?: string|null}  $filters
     */
    public function queryForBranch(int $branchId, array $filters = []): Builder
    {
        $query = Service::query()
            ->whereHas('branches', fn (Builder $q) => $q->where('branches.id', $branchId))
            ->with(['category:id,name', 'variants']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        return $query->orderBy('name');
    }

    /**
     * Finds one service of the branch, or null when it is not available there.
     */
    public function findForBranch(int $serviceId, int $branchId): ?Service
    {
        return $this->queryForBranch($branchId)->find($serviceId);
    }

    /**
     * Service payload with its sellable variants (each variant has its own price).
     *
     * @return array<string, mixed>
     */
    public function payload(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'category' => $service->category->name ?? 'Sin categoría',
            'base_price' => (string) $service->base_price,
            'duration_estimate' => $service->duration_estimate,
            'show_online' => (bool) $service->show_online,
            'variants' => $service->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => (string) $variant->price,
                'duration_estimate' => $variant->duration_estimate,
            ])->values()->all(),
        ];
    }
}
