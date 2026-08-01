<?php

namespace App\AiTools\Registrars;

use App\Services\BusinessHealthCheckService;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class BusinessHealthTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => null,
                'category'   => 'business health',
                'tool'       => (new Tool)->as('business_health_check')
                    ->for('Revisa los datos del negocio (stock, clientes en riesgo, márgenes, flujo de caja) y devuelve un resumen estructurado con severidad por categoría. No requiere parámetros — usa automáticamente la sucursal del usuario.

Este tool retorna un payload estructurado con hasta 4 categorías (stock_risk, churn_risk, margin_drop, cashflow). Cada categoría tiene una severidad: normal, warning o critical. Tu trabajo es resumir esto en español como 2-3 viñetas cortas, priorizadas y accionables, ordenadas por severidad (critical primero). No repitas todos los números del payload — elige los 1-2 datos más importantes por viñeta. Si todas las categorías son "normal", di de forma clara y breve que todo luce estable — no inventes un problema ni fuerces una sugerencia cuando no la hay. Nunca digas que has tomado una acción; solo estás sugiriendo, el usuario debe pedir explícitamente una acción de seguimiento por separado.')
                    ->using(function () use ($branchId) {
                        $result = app(BusinessHealthCheckService::class)->check($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}