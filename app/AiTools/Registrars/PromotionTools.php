<?php

namespace App\AiTools\Registrars;

use App\Services\PromotionReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class PromotionTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('active_promotions')
                    ->for('Listar las promociones actualmente activas')
                    ->using(function () use ($branchId) {
                        $result = app(PromotionReportService::class)->getActivePromotions($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('promotion_usage_stats')
                    ->for('Obtener estadísticas de uso de una promoción específica')
                    ->withNumberParameter('promotion_id', 'ID de la promoción')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (int $promotion_id, string $start_date, string $end_date) use ($branchId) {
                        $result = app(PromotionReportService::class)->getUsageStats(
                            $promotion_id,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            $branchId,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}