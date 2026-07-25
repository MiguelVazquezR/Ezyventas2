<?php

namespace App\AiTools\Registrars;

use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Services\CashRegisterReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class CashRegisterTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            // ════════════════ CASH REGISTERS ════════════════
            [
                'permission' => 'cash_registers.access',
                'category'   => 'cash registers',
                'tool'       => (new Tool)->as('list_cash_registers')
                    ->for('Listar todas las cajas registradoras de la sucursal con su estado (activa, en uso)')
                    ->using(function () use ($branchId) {
                        $registers = CashRegister::query()
                            ->where('branch_id', $branchId)
                            ->get(['id', 'name', 'is_active', 'in_use']);
                        return json_encode($registers, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.access',
                'category'   => 'cash registers',
                'tool'       => (new Tool)->as('search_cash_registers')
                    ->for('Buscar cajas registradoras por nombre')
                    ->withStringParameter('query', 'Nombre parcial de la caja registradora')
                    ->using(function (string $query) use ($branchId) {
                        $registers = CashRegister::query()
                            ->where('branch_id', $branchId)
                            ->where('name', 'LIKE', "%{$query}%")
                            ->limit(15)
                            ->get(['id', 'name', 'is_active', 'in_use']);
                        return json_encode($registers, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CASH REGISTER SESSIONS ════════════════
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('list_cash_register_sessions')
                    ->for('Listar sesiones de caja registradora con filtros opcionales: estado, rango de fechas, ID de caja')
                    ->withStringParameter('status', 'Estado de la sesión (abierta, cerrada) o null para todas')
                    ->withStringParameter('date_from', 'Fecha inicial YYYY-MM-DD o null')
                    ->withStringParameter('date_to', 'Fecha final YYYY-MM-DD o null')
                    ->withNumberParameter('cash_register_id', 'ID de caja registradora (opcional)')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 20, máximo 50)')
                    ->using(function (?string $status = null, ?string $date_from = null, ?string $date_to = null, ?int $cash_register_id = null, ?int $limit = 20) use ($branchId) {
                        $query = CashRegisterSession::whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId));

                        if ($status && $status !== 'null') {
                            $query->where('status', $status);
                        }
                        if ($date_from && $date_from !== 'null') {
                            $query->whereDate('opened_at', '>=', $date_from);
                        }
                        if ($date_to && $date_to !== 'null') {
                            $query->whereDate('opened_at', '<=', $date_to);
                        }
                        if ($cash_register_id) {
                            $query->where('cash_register_id', $cash_register_id);
                        }

                        $sessions = $query->with(['cashRegister:id,name', 'opener:id,name'])
                            ->orderBy('opened_at', 'desc')
                            ->limit(min($limit ?? 20, 50))
                            ->get()
                            ->map(fn ($s) => [
                                'id'              => $s->id,
                                'cash_register'   => $s->cashRegister?->name,
                                'opener'          => $s->opener?->name,
                                'status'          => $s->status->value,
                                'opened_at'       => $s->opened_at?->toDateTimeString(),
                                'closed_at'       => $s->closed_at?->toDateTimeString(),
                                'cash_difference' => (float) $s->cash_difference,
                            ]);

                        return json_encode($sessions, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('search_sessions_by_user')
                    ->for('Buscar sesiones de caja abiertas o cerradas por un usuario específico')
                    ->withStringParameter('user_query', 'Nombre parcial del usuario que abrió la sesión')
                    ->withStringParameter('status', 'Estado de la sesión (abierta, cerrada) o null para todas')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 10, máximo 30)')
                    ->using(function (string $user_query, ?string $status = null, ?int $limit = 10) use ($branchId) {
                        $query = CashRegisterSession::whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId))
                            ->whereHas('opener', fn ($q) => $q->where('name', 'LIKE', "%{$user_query}%"));

                        if ($status && $status !== 'null') {
                            $query->where('status', $status);
                        }

                        $sessions = $query->with(['cashRegister:id,name', 'opener:id,name'])
                            ->orderBy('opened_at', 'desc')
                            ->limit(min($limit ?? 10, 30))
                            ->get()
                            ->map(fn ($s) => [
                                'id'              => $s->id,
                                'cash_register'   => $s->cashRegister?->name,
                                'opener'          => $s->opener?->name,
                                'status'          => $s->status->value,
                                'opened_at'       => $s->opened_at?->toDateTimeString(),
                                'closed_at'       => $s->closed_at?->toDateTimeString(),
                                'cash_difference' => (float) $s->cash_difference,
                            ]);

                        return json_encode($sessions, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CASH REGISTER REPORTS ════════════════
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_session_summary')
                    ->for('Obtener el resumen de una sesión de caja: totales por método de pago y conciliación bancaria')
                    ->withNumberParameter('session_id', 'ID de la sesión de caja registradora')
                    ->using(function (int $session_id) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getSessionSummary($branchId, $session_id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_discrepancies')
                    ->for('Listar sesiones de caja con discrepancias entre el efectivo contado y el esperado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getDiscrepancies(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('daily_cash_close')
                    ->for('Obtener el cierre de caja de una fecha específica')
                    ->withStringParameter('date', 'Fecha en formato YYYY-MM-DD')
                    ->using(function (string $date) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getDailyClose($branchId, $date);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}