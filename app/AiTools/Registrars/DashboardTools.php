<?php

namespace App\AiTools\Registrars;

use App\Services\SalesDashboardService;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class DashboardTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'daily sales dashboard',
                'tool'       => (new Tool)->as('today_sales_summary')
                    ->for('Obtener los KPIs de ventas del día actual')
                    ->using(function () use ($branchId) {
                        $result = app(SalesDashboardService::class)->getTodaySales($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'weekly sales dashboard',
                'tool'       => (new Tool)->as('weekly_sales_trend')
                    ->for('Obtener la tendencia de ventas de los últimos 7 días')
                    ->using(function () use ($branchId) {
                        $result = app(SalesDashboardService::class)->getWeeklyTrend($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}