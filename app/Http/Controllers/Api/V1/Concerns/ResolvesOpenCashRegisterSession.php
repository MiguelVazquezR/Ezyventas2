<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\CashRegisterSessionStatus;
use App\Models\CashRegisterSession;
use Illuminate\Http\JsonResponse;

/**
 * Guard shared by every mobile write endpoint that touches the till: sales,
 * payments and cash refunds must belong to a session that is open in the
 * branch of the user. The web POS relies on the UI for this, the app must not.
 */
trait ResolvesOpenCashRegisterSession
{
    /**
     * Open session of the branch, or null when it is closed or foreign.
     */
    protected function openCashRegisterSession(int $sessionId, int $branchId): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->whereKey($sessionId)
            ->whereHas('cashRegister', fn ($query) => $query->where('branch_id', $branchId))
            ->first();
    }

    protected function sessionRequiredResponse(
        string $message = 'Necesitas una sesión de caja abierta para registrar ventas.',
    ): JsonResponse {
        return response()->json([
            'code' => 'session_required',
            'message' => $message,
        ], 422);
    }
}
