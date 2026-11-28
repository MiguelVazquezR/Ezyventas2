<?php

namespace App\Services\SmartCheckout;

use App\Models\SmartPosDetectionEvent;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Metrics of the "Modo Caja Inteligente" (Phase 2).
 *
 * Two responsibilities, both tenant-safe:
 *
 * 1. **Write** one detection event. The tenant and the branch always come from
 *    the authenticated user; any `subscription_id` / `branch_id` / `user_id`
 *    sent by the browser is ignored. A sale event may only reference a sale of
 *    the cashier's own branch.
 * 2. **Read** the aggregates of the day for the cashier's branch, with portable
 *    SQL only (the test suite runs on SQLite, production on MySQL): no
 *    `DATE_FORMAT`, no driver-specific function.
 *
 * The POS panel computes nothing on its own: it paints what this service
 * returns, so the indicator bar and the server can never disagree.
 */
class SmartCheckoutAnalyticsService
{
    /**
     * Registers one event of the mode.
     *
     * @param  array<string, mixed>  $data  Validated payload (StoreDetectionEventRequest).
     *
     * @throws ValidationException when a sale event references a sale of another branch.
     */
    public function recordEvent(User $user, array $data): SmartPosDetectionEvent
    {
        $branchId = (int) $user->branch_id;
        $subscriptionId = (int) ($user->branch?->subscription_id ?? 0);

        if ($branchId <= 0 || $subscriptionId <= 0) {
            throw new InvalidArgumentException('El usuario no tiene una sucursal válida para registrar eventos de Caja Inteligente.');
        }

        // The tenant is never taken from the payload: a terminal can only write
        // events for the branch of the cashier that is authenticated.
        unset($data['subscription_id'], $data['branch_id'], $data['user_id']);

        $saleId = $data['sale_id'] ?? null;

        if ($saleId !== null && ! $this->saleBelongsToBranch((int) $saleId, $branchId)) {
            throw ValidationException::withMessages([
                'sale_id' => 'La venta indicada no pertenece a la sucursal del cajero.',
            ]);
        }

        return SmartPosDetectionEvent::create([
            'subscription_id' => $subscriptionId,
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'session_id' => (string) $data['session_id'],
            'event_type' => (string) $data['event_type'],
            'objects' => (int) ($data['objects'] ?? 0),
            'auto_accepted' => (int) ($data['auto_accepted'] ?? 0),
            'confirmed' => (int) ($data['confirmed'] ?? 0),
            'corrected' => (int) ($data['corrected'] ?? 0),
            'unknown' => (int) ($data['unknown'] ?? 0),
            'latency_ms' => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'model_version' => $data['model_version'] ?? null,
            'sale_id' => $saleId !== null ? (int) $saleId : null,
            'duration_seconds' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            // The panel may send the moment of the event (offline batch); the
            // default is "now".
            'occurred_at' => isset($data['occurred_at']) && $data['occurred_at'] !== null
                ? Carbon::parse($data['occurred_at'])
                : now(),
        ]);
    }

    /**
     * Aggregates of today for the cashier's branch.
     *
     * Formulas (the indicator bar must use exactly these):
     *
     * - detected            = Σ objects of the `analysis` events
     * - auto_accepted       = Σ auto_accepted of the `analysis` events
     * - confirmed           = Σ confirmed of the `analysis` events
     * - corrected           = Σ corrected of the `analysis` events
     * - unknown             = Σ unknown of the `analysis` events
     * - accuracy            = auto_accepted / (auto_accepted + confirmed + corrected) × 100,
     *                         1 decimal; 0 when the denominator is 0
     * - sales_with_mode     = count of `sale` events whose sale_id is not null
     * - unknowns_registered = Σ unknown (same as `unknown`, exposed for the UI)
     * - avg_seconds_per_sale = AVG(duration_seconds) of the `sale` events, 1 decimal
     * - avg_latency_ms      = AVG(latency_ms) of the `analysis` events, 1 decimal
     * - analyses            = count of `analysis` events
     * - last_event_at       = MAX(occurred_at) of every event of the day, ISO 8601
     *
     * "Today" is the application day (America/Mexico_City) and only the branch
     * of the authenticated user is counted.
     *
     * @return array<string, int|float|string|null>
     */
    public function todayMetrics(User $user): array
    {
        $branchId = (int) $user->branch_id;
        $from = now()->startOfDay();
        $to = now()->endOfDay();

        $base = SmartPosDetectionEvent::query()
            ->forBranch($branchId)
            ->betweenDates($from, $to);

        // One aggregate query for the analyses. COALESCE / AVG / CASE WHEN are
        // portable to both MySQL and SQLite (the test database).
        $analysis = (clone $base)->ofType(SmartPosDetectionEvent::TYPE_ANALYSIS)
            ->selectRaw('COUNT(*) as analyses')
            ->selectRaw('COALESCE(SUM(objects), 0) as detected')
            ->selectRaw('COALESCE(SUM(auto_accepted), 0) as auto_accepted')
            ->selectRaw('COALESCE(SUM(confirmed), 0) as confirmed')
            ->selectRaw('COALESCE(SUM(corrected), 0) as corrected')
            ->selectRaw('COALESCE(SUM(unknown), 0) as unknown')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->first();

        $sale = (clone $base)->ofType(SmartPosDetectionEvent::TYPE_SALE)
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_id IS NOT NULL THEN 1 ELSE 0 END), 0) as sales_with_mode')
            ->selectRaw('AVG(duration_seconds) as avg_seconds_per_sale')
            ->first();

        $autoAccepted = (int) ($analysis->auto_accepted ?? 0);
        $confirmed = (int) ($analysis->confirmed ?? 0);
        $corrected = (int) ($analysis->corrected ?? 0);
        $unknown = (int) ($analysis->unknown ?? 0);

        $accuracyDenominator = $autoAccepted + $confirmed + $corrected;

        $lastEventAt = (clone $base)->max('occurred_at');

        return [
            'detected' => (int) ($analysis->detected ?? 0),
            'auto_accepted' => $autoAccepted,
            'confirmed' => $confirmed,
            'corrected' => $corrected,
            'unknown' => $unknown,
            'accuracy' => $accuracyDenominator > 0
                ? round($autoAccepted / $accuracyDenominator * 100, 1)
                : 0.0,
            'sales_with_mode' => (int) ($sale->sales_with_mode ?? 0),
            'unknowns_registered' => $unknown,
            'avg_seconds_per_sale' => round((float) ($sale->avg_seconds_per_sale ?? 0), 1),
            'avg_latency_ms' => round((float) ($analysis->avg_latency_ms ?? 0), 1),
            'analyses' => (int) ($analysis->analyses ?? 0),
            'last_event_at' => $lastEventAt !== null
                ? Carbon::parse($lastEventAt)->toIso8601String()
                : null,
        ];
    }

    /**
     * Whether the sale exists and belongs to the branch of the cashier.
     */
    private function saleBelongsToBranch(int $saleId, int $branchId): bool
    {
        return Transaction::query()
            ->whereKey($saleId)
            ->where('branch_id', $branchId)
            ->exists();
    }
}
