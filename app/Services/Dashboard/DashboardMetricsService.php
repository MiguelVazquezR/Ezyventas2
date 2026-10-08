<?php

namespace App\Services\Dashboard;

use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashRegisters\CashRegisterSessionQueryService;
use App\Services\SalesDashboardService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * KPIs of the home screen of the mobile app.
 *
 * Sales figures come from SalesDashboardService — the same source used by the
 * web dashboard — so both clients always show the same numbers.
 */
class DashboardMetricsService
{
    public function __construct(
        private readonly SalesDashboardService $salesDashboard,
        private readonly CashRegisterSessionQueryService $cashRegisterSessions,
    ) {}

    /**
     * Today sales, average ticket, comparison with yesterday and weekly trend.
     *
     * @return array<string, mixed>
     */
    public function salesSummary(int $branchId): array
    {
        $today = $this->salesDashboard->getTodaySales($branchId);
        $count = $today['transaction_count'];
        $total = $today['total_sales'];

        return [
            'today_total' => $this->money($total),
            'today_count' => $count,
            'average_ticket' => $this->money($count > 0 ? $total / $count : 0),
            'yesterday_total' => $this->money($this->salesTotalForDay($branchId, now()->subDay())),
            'weekly_trend' => array_map(
                fn (array $day) => ['day' => $day['day'], 'total' => $this->money($day['total'])],
                $this->salesDashboard->getWeeklyTrend($branchId),
            ),
        ];
    }

    /**
     * Balance owed by the customers of the branch, as a positive figure.
     *
     * The customer balance is negative when they owe money (same criterion as
     * the web dashboard), so the sign is flipped here once.
     */
    public function totalCustomerDebt(int $branchId): string
    {
        $debt = (float) Customer::query()
            ->where('branch_id', $branchId)
            ->where('balance', '<', 0)
            ->sum('balance');

        return $this->money($debt * -1);
    }

    /**
     * Cash register the user is working in, or the absence of it.
     *
     * Reuses the payload of `GET /cash-register-sessions/current` so the home
     * screen and the POS always describe the same shift.
     *
     * @return array<string, mixed>
     */
    public function cashRegisterState(User $user): array
    {
        $session = $this->cashRegisterSessions->activeSession($user);

        return [
            'has_open_session' => $session !== null,
            'session' => $session,
        ];
    }

    /**
     * Total of a single day, with the same criterion as `getTodaySales`.
     */
    private function salesTotalForDay(int $branchId, CarbonInterface $day): float
    {
        $row = Transaction::query()
            ->where('branch_id', $branchId)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->selectRaw('SUM(subtotal - total_discount + total_tax) as total_sales')
            ->first();

        return (float) ($row->total_sales ?? 0);
    }

    /**
     * Stock KPIs of the branch: simple products plus variants.
     *
     * "Healthy" means stock above the minimum configured for the branch; it is
     * the `in_stock_count` of the web dashboard, renamed so nobody mistakes it
     * for "has stock".
     *
     * @return array<string, mixed>
     */
    public function inventorySummary(int $branchId, int $lowStockLimit = 5): array
    {
        $simple = DB::table('branch_product as bp')
            ->join('products as p', 'p.id', '=', 'bp.product_id')
            ->where('bp.branch_id', $branchId)
            // Products with variants are excluded: their stock lives in each variant.
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_attributes as pa')
                    ->whereColumn('pa.product_id', 'p.id');
            })
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('SUM(p.cost_price * bp.current_stock) as total_cost')
            ->selectRaw('SUM(p.selling_price * bp.current_stock) as total_sale_value')
            ->selectRaw(implode(', ', $this->stockCounters('bp')))
            ->first();

        $variants = DB::table('branch_product_attribute as bpa')
            ->join('product_attributes as pa', 'pa.id', '=', 'bpa.product_attribute_id')
            ->join('products as p', 'p.id', '=', 'pa.product_id')
            ->where('bpa.branch_id', $branchId)
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('SUM(p.cost_price * bpa.current_stock) as total_cost')
            ->selectRaw('SUM((p.selling_price + pa.selling_price_modifier) * bpa.current_stock) as total_sale_value')
            ->selectRaw(implode(', ', $this->stockCounters('bpa')))
            ->first();

        return [
            'total_items' => (int) (($simple->total_items ?? 0) + ($variants->total_items ?? 0)),
            'healthy_stock_count' => (int) (($simple->healthy_stock_count ?? 0) + ($variants->healthy_stock_count ?? 0)),
            'low_stock_count' => (int) (($simple->low_stock_count ?? 0) + ($variants->low_stock_count ?? 0)),
            'out_of_stock_count' => (int) (($simple->out_of_stock_count ?? 0) + ($variants->out_of_stock_count ?? 0)),
            'total_cost' => $this->money((float) (($simple->total_cost ?? 0) + ($variants->total_cost ?? 0))),
            'total_sale_value' => $this->money((float) (($simple->total_sale_value ?? 0) + ($variants->total_sale_value ?? 0))),
            // Short list of what has to be reordered (same source as the web).
            'low_stock_products' => $this->salesDashboard->getLowStockProducts($branchId, $lowStockLimit),
        ];
    }

    /**
     * Service orders of the branch grouped by status. Every status of the enum
     * is always present (0 when there is none), so the app never has to treat a
     * missing key as zero.
     *
     * @return array<string, mixed>
     */
    public function serviceOrdersByStatus(int $branchId): array
    {
        $counts = ServiceOrder::query()
            ->where('branch_id', $branchId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];
        $total = 0;

        foreach (ServiceOrderStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $byStatus[$status->value] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'by_status' => $byStatus,
        ];
    }

    /**
     * Counters shared by simple products and product variants.
     *
     * @return array<string, string>
     */
    private function stockCounters(string $alias): array
    {
        $stock = $alias . '.current_stock';
        $min = 'COALESCE(' . $alias . '.min_stock, 0)';

        return [
            'healthy_stock_count' => "SUM(CASE WHEN {$stock} > {$min} THEN 1 ELSE 0 END) as healthy_stock_count",
            'low_stock_count' => "SUM(CASE WHEN {$stock} > 0 AND {$stock} <= {$min} THEN 1 ELSE 0 END) as low_stock_count",
            'out_of_stock_count' => "SUM(CASE WHEN {$stock} <= 0 THEN 1 ELSE 0 END) as out_of_stock_count",
        ];
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
