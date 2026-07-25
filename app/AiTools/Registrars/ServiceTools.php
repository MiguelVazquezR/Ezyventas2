<?php

namespace App\AiTools\Registrars;

use App\Models\Service;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ServiceTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'services.catalog.access',
                'category'   => 'services',
                'tool'       => (new Tool)->as('search_services')
                    ->for('Buscar servicios del catálogo por nombre')
                    ->withStringParameter('query', 'Nombre parcial del servicio a buscar')
                    ->using(function (string $query) use ($branchId) {
                        $services = Service::query()
                            ->where('branch_id', $branchId)
                            ->where('name', 'LIKE', "%{$query}%")
                            ->with('category:id,name')
                            ->limit(15)
                            ->get(['id', 'name', 'base_price', 'duration_estimate', 'category_id', 'slug']);
                        return json_encode($services, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.catalog.access',
                'category'   => 'services',
                'tool'       => (new Tool)->as('get_service_details')
                    ->for('Obtener los detalles completos de un servicio: precio base, duración estimada, variantes y categoría')
                    ->withNumberParameter('service_id', 'ID del servicio')
                    ->using(function (int $service_id) use ($branchId) {
                        $service = Service::where('branch_id', $branchId)
                            ->with(['category:id,name', 'variants:id,service_id,name,price,duration_estimate'])
                            ->findOrFail($service_id);

                        return json_encode([
                            'id'                => $service->id,
                            'name'              => $service->name,
                            'slug'              => $service->slug,
                            'description'       => $service->description,
                            'base_price'        => (float) $service->base_price,
                            'duration_estimate' => $service->duration_estimate,
                            'show_online'       => $service->show_online,
                            'category'          => $service->category?->name,
                            'variants'          => $service->variants->map(fn ($v) => [
                                'id'                => $v->id,
                                'name'              => $v->name,
                                'price'             => (float) $v->price,
                                'duration_estimate' => $v->duration_estimate,
                            ]),
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}