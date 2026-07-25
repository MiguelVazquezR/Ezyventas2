<?php

namespace App\AiTools\Registrars;

use App\Services\StaffPerformanceService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class StaffPerformanceTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;
        $subscriptionId = $user->branch->subscription_id;

        return [
            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('sales_by_employee')
                    ->for('Obtener las ventas agrupadas por empleado en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(StaffPerformanceService::class)->salesByEmployee(
                            $branchId, Carbon::parse($start_date), Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('ranking_by_branch')
                    ->for('Obtener el ranking de sucursales por ventas en un período (solo para suscripciones multi-sucursal)')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($subscriptionId) {
                        $result = app(StaffPerformanceService::class)->rankingByBranch(
                            $subscriptionId, Carbon::parse($start_date), Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}