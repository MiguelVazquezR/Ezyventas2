<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusinessHealthCheckService
{
    /**
     * Orchestrator: runs all 4 detectors and returns a consolidated payload.
     * Cached for 15 minutes keyed by branch ID.
     *
     * @param  int|null  $branchId
     */
    public function check(?int $branchId): array
    {
        if ($branchId === null) {
            return [
                'error'        => 'No se pudo determinar la sucursal del usuario. Contacta a soporte.',
                'generated_at' => now()->toISOString(),
            ];
        }

        // Try cache first; if cache store fails (e.g. missing table), run detectors directly
        try {
            return Cache::remember(
                "business_health:{$branchId}",
                900, // 15 minutes
                function () use ($branchId) {
                    return $this->executeDetectors($branchId);
                }
            );
        } catch (\Throwable $e) {
            Log::warning('[BusinessHealthCheck] Cache layer failed, running detectors without cache.', [
                'branch_id' => $branchId,
                'error'     => $e->getMessage(),
            ]);

            return $this->executeDetectors($branchId);
        }
    }

    /**
     * Execute all detectors, each wrapped in its own try/catch so one
     * failing detector doesn't take down the entire payload.
     */
    private function executeDetectors(int $branchId): array
    {
        return [
            'stock_risk'   => $this->safeDetect('stock_risk', $branchId, fn () => $this->detectStockRisk($branchId)),
            'churn_risk'   => $this->safeDetect('churn_risk', $branchId, fn () => $this->detectChurnRisk($branchId)),
            'margin_drop'  => $this->safeDetect('margin_drop', $branchId, fn () => $this->detectMarginAnomalies($branchId)),
            'cashflow'     => $this->safeDetect('cashflow', $branchId, fn () => $this->detectCashflowRisk($branchId)),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Run a detector safely — catch any Throwable, log it, and return an error result.
     */
    private function safeDetect(string $detector, int $branchId, callable $callback): array
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::error("[BusinessHealthCheck] Detector '{$detector}' failed.", [
                'branch_id' => $branchId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return [
                'severity' => 'error',
                'summary'  => "El detector '{$detector}' falló por un error interno. El equipo técnico ha sido notificado.",
                'data'     => [],
            ];
        }
    }

    // ═════════════════════════════════════════════════════════════
    // Detector 1: Stock risk (sales velocity)
    // ═════════════════════════════════════════════════════════════

    private function detectStockRisk(int $branchId): array
    {
        $fourteenDaysAgo = now()->subDays(14)->startOfDay();

        // Daily average sale rate per product from last 14 days
        $salesVelocity = DB::table('transactions_items')
            ->join('transactions', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.created_at', '>=', $fourteenDaysAgo)
            ->whereNotIn('transactions.status', ['cancelado', 'cambiado'])
            ->where('transactions_items.itemable_type', Product::class)
            ->groupBy('transactions_items.itemable_id')
            ->select(
                'transactions_items.itemable_id',
                DB::raw('SUM(transactions_items.quantity) as total_qty')
            )
            ->get()
            ->filter(fn ($row) => $row->total_qty > 0)
            ->mapWithKeys(function ($row) {
                $dailyRate = $row->total_qty / 14;
                return [$row->itemable_id => $dailyRate];
            });

        if ($salesVelocity->isEmpty()) {
            Log::info('[BusinessHealthCheck] Stock risk: no sales data found in last 14 days.', [
                'branch_id' => $branchId,
            ]);

            return $this->normalResult('No hay datos de ventas suficientes en los últimos 14 días para evaluar riesgo de stock.');
        }

        // Current stock from branch_product pivot
        $stockLevels = DB::table('branch_product')
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $salesVelocity->keys())
            ->pluck('current_stock', 'product_id');

        // Compute risk
        $risks = collect();
        foreach ($salesVelocity as $productId => $dailyRate) {
            $currentStock = (float) ($stockLevels->get($productId, 0));
            if ($currentStock <= 0) {
                // Already out of stock — highest risk
                $risks->push([
                    'product_id'     => $productId,
                    'days_remaining' => 0,
                    'daily_rate'     => round($dailyRate, 2),
                    'current_stock'  => $currentStock,
                    'severity'       => 'critical',
                ]);
                continue;
            }

            $daysRemaining = $currentStock / $dailyRate;

            if ($daysRemaining >= 5) {
                continue; // not at risk
            }

            $severity = $daysRemaining < 2 ? 'critical' : 'warning';

            $risks->push([
                'product_id'     => $productId,
                'days_remaining' => round($daysRemaining, 1),
                'daily_rate'     => round($dailyRate, 2),
                'current_stock'  => $currentStock,
                'severity'       => $severity,
            ]);
        }

        $risks = $risks->sortBy('days_remaining')->take(5)->values();

        if ($risks->isEmpty()) {
            return $this->normalResult('Todos los productos con ventas tienen stock suficiente para al menos 5 días.');
        }

        // Attach product names
        $productIds = $risks->pluck('product_id');
        $productNames = Product::whereIn('id', $productIds)->pluck('name', 'id');

        $enriched = $risks->map(function ($row) use ($productNames) {
            $row['product_name'] = $productNames->get($row['product_id'], 'Desconocido');
            return $row;
        });

        $criticalCount = $enriched->where('severity', 'critical')->count();
        $overallSeverity = $criticalCount > 0 ? 'critical' : 'warning';

        return [
            'severity' => $overallSeverity,
            'summary'  => "{$enriched->count()} producto(s) en riesgo de agotar stock en menos de 5 días ({$criticalCount} críticos).",
            'data'     => $enriched->toArray(),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Detector 2: Customer churn risk
    // ═════════════════════════════════════════════════════════════

    private function detectChurnRisk(int $branchId): array
    {
        // Use raw subquery to find customers with >= 3 transactions, avoiding
        // edge cases with Eloquent's whereHas count operator across drivers.
        $customerIds = Customer::where('branch_id', $branchId)
            ->whereHas('transactions', function ($q) {
                $q->whereNotIn('status', ['cancelado', 'cambiado']);
            }, '>=', 3)
            ->pluck('id');

        if ($customerIds->isEmpty()) {
            return $this->normalResult('No hay clientes con suficiente historial de compras para evaluar riesgo de abandono.');
        }

        // Fetch customers with their transaction dates (only completed, non-canceled)
        $customers = Customer::whereIn('id', $customerIds)
            ->with(['transactions' => function ($q) {
                $q->whereNotIn('status', ['cancelado', 'cambiado'])
                    ->orderBy('created_at', 'asc')
                    ->select('id', 'customer_id', 'created_at');
            }])
            ->get(['id', 'name', 'email', 'phone']);

        $risks = collect();
        $now = now();

        foreach ($customers as $customer) {
            $dates = $customer->transactions->pluck('created_at')->map(fn ($d) => Carbon::parse($d));

            if ($dates->count() < 3) {
                continue;
            }

            // Per-customer average interval between consecutive purchases
            $intervals = [];
            for ($i = 0; $i < $dates->count() - 1; $i++) {
                $intervals[] = $dates[$i]->diffInDays($dates[$i + 1]);
            }

            if (empty($intervals)) {
                continue;
            }

            $avgInterval = array_sum($intervals) / count($intervals);
            $currentGap = (int) $dates->last()->diffInDays($now);

            if ($avgInterval <= 0 || $currentGap <= 0) {
                continue;
            }

            $ratio = $currentGap / $avgInterval;

            if ($ratio < 2.0) {
                continue; // not at risk
            }

            $severity = $ratio >= 3.0 ? 'critical' : 'warning';

            $risks->push([
                'customer_id'        => $customer->id,
                'customer_name'      => $customer->name,
                'customer_email'     => $customer->email,
                'current_gap_days'   => $currentGap,
                'avg_interval_days'  => round($avgInterval, 1),
                'purchase_count'     => $dates->count(),
                'last_purchase_date' => $dates->last()->toDateString(),
                'severity'           => $severity,
            ]);
        }

        $risks = $risks->sortByDesc(fn ($r) => $r['current_gap_days'] / max($r['avg_interval_days'], 1))
            ->take(5)
            ->values();

        if ($risks->isEmpty()) {
            return $this->normalResult('Todos los clientes con historial están dentro de su frecuencia de compra habitual.');
        }

        $criticalCount = $risks->where('severity', 'critical')->count();
        $overallSeverity = $criticalCount > 0 ? 'critical' : 'warning';

        return [
            'severity' => $overallSeverity,
            'summary'  => "{$risks->count()} cliente(s) con brecha de compra significativamente mayor a su frecuencia habitual ({$criticalCount} críticos).",
            'data'     => $risks->toArray(),
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Detector 3: Margin anomalies
    // ═════════════════════════════════════════════════════════════

    private function detectMarginAnomalies(int $branchId): array
    {
        $now = now()->startOfDay();
        $last30Start = (clone $now)->subDays(30)->startOfDay();
        $prior30Start = (clone $now)->subDays(60)->startOfDay();
        $prior30End = (clone $now)->subDays(31)->endOfDay();

        // Current period (last 30 days): avg margin per product
        $currentMargins = $this->computeMarginByProduct($branchId, $last30Start, $now);

        // Prior period (days 31-60): avg margin per product
        $priorMargins = $this->computeMarginByProduct($branchId, $prior30Start, $prior30End);

        if ($currentMargins->isEmpty()) {
            Log::info('[BusinessHealthCheck] Margin anomalies: no sales data found in current 30-day window.', [
                'branch_id' => $branchId,
            ]);

            return $this->normalResult('No hay datos de ventas suficientes para evaluar cambios en márgenes.');
        }

        $risks = collect();

        foreach ($currentMargins as $productId => $currentData) {
            if (! isset($priorMargins[$productId])) {
                continue; // no prior data to compare
            }

            $priorData = $priorMargins[$productId];

            // Exclude near-zero-volume products (less than 5 units in either period)
            if ($currentData['qty'] < 5 || $priorData['qty'] < 5) {
                continue;
            }

            $currentMargin = $currentData['margin_pct'];
            $priorMargin = $priorData['margin_pct'];
            $drop = $priorMargin - $currentMargin;

            if ($drop <= 15) {
                continue; // not a significant drop
            }

            $severity = $drop > 25 ? 'critical' : 'warning';

            $risks->push([
                'product_id'         => $productId,
                'current_margin_pct' => round($currentMargin, 1),
                'prior_margin_pct'   => round($priorMargin, 1),
                'margin_drop_pp'     => round($drop, 1),
                'current_qty'        => $currentData['qty'],
                'prior_qty'          => $priorData['qty'],
                'severity'           => $severity,
            ]);
        }

        $risks = $risks->sortByDesc('margin_drop_pp')->take(5)->values();

        if ($risks->isEmpty()) {
            return $this->normalResult('No se detectaron caídas significativas en márgenes por producto en los últimos 30 días.');
        }

        $criticalCount = $risks->where('severity', 'critical')->count();
        $overallSeverity = $criticalCount > 0 ? 'critical' : 'warning';

        // Attach product names
        $productIds = $risks->pluck('product_id');
        $productNames = Product::whereIn('id', $productIds)->pluck('name', 'id');

        $enriched = $risks->map(function ($row) use ($productNames) {
            $row['product_name'] = $productNames->get($row['product_id'], 'Desconocido');
            return $row;
        });

        return [
            'severity' => $overallSeverity,
            'summary'  => "{$enriched->count()} producto(s) con caída significativa de margen respecto al período anterior ({$criticalCount} críticos).",
            'data'     => $enriched->toArray(),
        ];
    }

    /**
     * Compute average margin percentage per product for a given date range.
     * Returns a collection keyed by product_id with margin_pct and qty.
     */
    private function computeMarginByProduct(int $branchId, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        $rows = DB::table('transactions_items')
            ->join('transactions', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->join('products', 'products.id', '=', 'transactions_items.itemable_id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<=', $end)
            ->whereNotIn('transactions.status', ['cancelado', 'cambiado'])
            ->where('transactions_items.itemable_type', Product::class)
            ->where('products.cost_price', '>', 0)
            ->groupBy('transactions_items.itemable_id')
            ->select(
                'transactions_items.itemable_id as product_id',
                DB::raw('SUM(transactions_items.quantity) as total_qty'),
                DB::raw('SUM(transactions_items.line_total) as total_revenue'),
                DB::raw('SUM(transactions_items.quantity * products.cost_price) as total_cost')
            )
            ->get();

        return $rows->mapWithKeys(function ($row) {
            $marginPct = $row->total_revenue > 0
                ? (($row->total_revenue - $row->total_cost) / $row->total_revenue) * 100
                : 0;

            return [$row->product_id => [
                'qty'        => (int) $row->total_qty,
                'margin_pct' => $marginPct,
            ]];
        });
    }

    // ═════════════════════════════════════════════════════════════
    // Detector 4: Cashflow/aging risk
    // ═════════════════════════════════════════════════════════════

    private function detectCashflowRisk(int $branchId): array
    {
        $aging = app(QuoteInvoiceReportService::class)->getInvoiceAging($branchId);

        $totalOutstanding = $aging['total_outstanding']['total'] ?? 0;

        if ($totalOutstanding <= 0) {
            return $this->normalResult('No hay facturas pendientes de cobro. El flujo de caja no presenta riesgos de cobranza.');
        }

        $overdue61to90 = $aging['buckets']['61-90']['total'] ?? 0;
        $overdue90plus = $aging['buckets']['90+']['total'] ?? 0;
        $overdueTotal = $overdue61to90 + $overdue90plus;

        $overdueProportion = ($overdueTotal / $totalOutstanding) * 100;

        if ($overdueProportion <= 30) {
            return $this->normalResult('La proporción de facturas vencidas (61+ días) es baja: ' . round($overdueProportion, 1) . '% del total pendiente.');
        }

        $severity = $overdueProportion > 50 ? 'critical' : 'warning';

        return [
            'severity' => $severity,
            'summary'  => 'Proporción de facturas vencidas por más de 60 días: ' . round($overdueProportion, 1) . '% del total pendiente de cobro.',
            'data'     => [
                'total_outstanding'        => round($totalOutstanding, 2),
                'total_outstanding_count'  => $aging['total_outstanding']['count'] ?? 0,
                'overdue_61_90_total'      => round($overdue61to90, 2),
                'overdue_90_plus_total'    => round($overdue90plus, 2),
                'overdue_proportion_pct'   => round($overdueProportion, 1),
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════

    private function normalResult(string $summary): array
    {
        return [
            'severity' => 'normal',
            'summary'  => $summary,
            'data'     => [],
        ];
    }
}