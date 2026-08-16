<?php

namespace App\AiTools\Registrars;

use App\Services\TransactionQueryService;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class TransactionTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('recent_transactions')
                    ->for('Obtener las transacciones más recientes de una sucursal')
                    ->withNumberParameter('limit', 'Cantidad máxima de transacciones (máx 20)')
                    ->using(function (int $limit = 10) use ($branchId) {
                        $result = app(TransactionQueryService::class)->search($branchId, [
                            'limit' => min($limit, 20),
                        ]);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('search_transactions')
                    ->for('Buscar transacciones con filtros: estado, método de pago, canal y rango de fechas')
                    ->withStringParameter('status', 'Estado de la transacción (completado, pendiente, cancelado, etc.) o null')
                    ->withStringParameter('payment_method', 'Método de pago (efectivo, tarjeta, transferencia) o null')
                    ->withStringParameter('date_from', 'Fecha inicial YYYY-MM-DD o null')
                    ->withStringParameter('date_to', 'Fecha final YYYY-MM-DD o null')
                    ->withStringParameter('channel', 'Canal de venta (tienda, en_linea) o null')
                    ->using(function (?string $status = null, ?string $payment_method = null, ?string $date_from = null, ?string $date_to = null, ?string $channel = null) use ($branchId) {
                        $filters = array_filter([
                            'status'         => $status,
                            'payment_method' => $payment_method,
                            'date_from'      => $date_from,
                            'date_to'        => $date_to,
                            'channel'        => $channel,
                        ], fn ($v) => $v !== null && $v !== 'null');
                        $result = app(TransactionQueryService::class)->search($branchId, $filters);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}