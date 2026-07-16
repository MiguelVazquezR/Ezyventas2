<?php

namespace App\Services;

use App\Models\CashRegisterSession;
use App\Models\SessionCashMovement;
use Carbon\Carbon;

class CashRegisterReportService
{
    public function getSessionSummary(int $branchId, int $sessionId): array
    {
        $session = CashRegisterSession::whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId))
            ->findOrFail($sessionId);

        return [
            'session_id'           => $session->id,
            'status'               => $session->status->value,
            'opened_at'            => $session->opened_at?->toDateTimeString(),
            'closed_at'            => $session->closed_at?->toDateTimeString(),
            'opening_cash_balance' => (float) $session->opening_cash_balance,
            'closing_cash_balance' => (float) $session->closing_cash_balance,
            'calculated_cash_total'=> (float) $session->calculated_cash_total,
            'cash_difference'      => (float) $session->cash_difference,
            'totals'               => $session->getCompletedPaymentTotals(),
            'bank_summary'         => $session->calculateBankAccountSummary(
                $session->opener, true
            ),
        ];
    }

    public function getDiscrepancies(int $branchId, Carbon $start, Carbon $end): array
    {
        $sessions = CashRegisterSession::whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', 'cerrada')
            ->whereBetween('opened_at', [$start, $end])
            ->whereRaw('ABS(COALESCE(cash_difference, 0)) > 0.01')
            ->orderBy('opened_at', 'desc')
            ->get();

        return $sessions->map(function ($session) {
            $cashMovementsIn = SessionCashMovement::where('cash_register_session_id', $session->id)
                ->where('type', 'INFLOW')
                ->sum('amount');

            $cashMovementsOut = SessionCashMovement::where('cash_register_session_id', $session->id)
                ->where('type', 'OUTFLOW')
                ->sum('amount');

            return [
                'session_id'         => $session->id,
                'opened_at'          => $session->opened_at?->toDateTimeString(),
                'closed_at'          => $session->closed_at?->toDateTimeString(),
                'expected_cash'      => (float) $session->calculated_cash_total,
                'counted_cash'       => (float) $session->closing_cash_balance,
                'difference'         => (float) $session->cash_difference,
                'cash_inflows'       => (float) $cashMovementsIn,
                'cash_outflows'      => (float) $cashMovementsOut,
            ];
        })->toArray();
    }

    public function getDailyClose(int $branchId, string $date): array
    {
        $dateObj = Carbon::parse($date);

        $sessions = CashRegisterSession::whereHas('cashRegister', fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('opened_at', $dateObj)
            ->orderBy('opened_at')
            ->get();

        return $sessions->map(function ($session) {
            return [
                'session_id'        => $session->id,
                'status'            => $session->status->value,
                'opened_at'         => $session->opened_at?->toDateTimeString(),
                'closed_at'         => $session->closed_at?->toDateTimeString(),
                'closing_cash'      => (float) $session->closing_cash_balance,
                'cash_difference'   => (float) $session->cash_difference,
                'payment_totals'    => $session->getCompletedPaymentTotals(),
                'transaction_count' => $session->transactions()->count(),
            ];
        })->toArray();
    }
}
