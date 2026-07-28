<?php

namespace App\AiTools\Registrars;

use App\Services\FinancialReportService;
use App\Services\InventoryReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ReportTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'financial_reports.access',
                'category'   => 'financial reports',
                'tool'       => (new Tool)->as('financial_report')
                    ->for('Obtener KPIs financieros, ventas por canal, gastos por categoría, distribución de ventas por hora (cuando el rango es un solo día), gráfica de tendencia y resumen de bancos para un rango de fechas determinado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $service = new FinancialReportService(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        $data = $service->generateReportData();
                        return json_encode($data, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.access',
                'category'   => 'inventory',
                'tool'       => (new Tool)->as('inventory_dead_stock')
                    ->for('Listar productos que NO han tenido ventas en los últimos N días (inventario muerto)')
                    ->withNumberParameter('days', 'Días sin movimiento, ej. 30, 60, 90')
                    ->withStringParameter('category_id', 'ID de categoría (opcional, usar null para todas)')
                    ->using(function (int $days, ?string $category_id = null) use ($branchId) {
                        $categoryId = $category_id && $category_id !== 'null' ? (int) $category_id : null;
                        $result = app(InventoryReportService::class)->deadStock(
                            $branchId, Carbon::now()->subDays($days), Carbon::now(), $categoryId,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'financial_reports.access',
                'category'   => 'financial reports',
                'tool'       => (new Tool)->as('monthly_revenue_trend')
                    ->for('Obtener la tendencia de ingresos mensuales de los últimos N meses, con tasa de crecimiento mes contra mes')
                    ->withNumberParameter('months', 'Cantidad de meses (por defecto 6, máximo 24)')
                    ->using(function (?int $months = 6) use ($branchId) {
                        $result = app(FinancialReportService::class)->monthlyRevenueTrend(
                            $branchId,
                            min($months ?? 6, 24),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}