<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesDashboardService
{
    public function getTodaySales(int $branchId): array
    {
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $aggregates = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->selectRaw('SUM(subtotal - total_discount + total_tax) as total_sales')
            ->selectRaw('COUNT(*) as total_count')
            ->first();

        return [
            'total_sales'      => (float) ($aggregates->total_sales ?? 0),
            'transaction_count' => (int) ($aggregates->total_count ?? 0),
        ];
    }

    public function getWeeklyTrend(int $branchId): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = now()->endOfWeek(Carbon::SUNDAY);

        $trendData = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(total_discount) as total_discount'),
                DB::raw('SUM(total_tax) as total_tax'),
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->keyBy('date');

        $weekSales = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateString = $date->format('Y-m-d');
            $dayData = $trendData->get($dateString);

            $total = 0;
            if ($dayData) {
                $total = ($dayData->total_subtotal - $dayData->total_discount) + $dayData->total_tax;
            }

            $weekSales[] = [
                'day'   => $date->translatedFormat('D'),
                'total' => (float) $total,
            ];
        }

        return $weekSales;
    }

    public function getActivePromotionsCount(int $branchId): int
    {
        return Promotion::where('subscription_id', function ($query) use ($branchId) {
                $query->select('subscription_id')
                    ->from('branches')
                    ->where('id', $branchId);
            })
            ->where('is_active', true)
            ->whereDate('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', now());
            })
            ->count();
    }

    public function getLowStockProducts(int $branchId, int $threshold = 5): array
    {
        // Simple products with low stock
        $simpleProducts = DB::table('branch_product as bp')
            ->join('products as p', 'p.id', '=', 'bp.product_id')
            ->where('bp.branch_id', $branchId)
            ->where('bp.current_stock', '<=', DB::raw('COALESCE(bp.min_stock, 0)'))
            ->where('bp.current_stock', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_attributes as pa')
                    ->whereColumn('pa.product_id', 'p.id');
            })
            ->select('p.id', 'p.name', 'p.sku', 'bp.current_stock', 'bp.min_stock')
            ->get()
            ->map(fn ($row) => [
                'id'            => $row->id,
                'name'          => $row->name,
                'sku'           => $row->sku,
                'current_stock' => (int) $row->current_stock,
                'min_stock'     => (int) ($row->min_stock ?? 0),
            ]);

        // Variant products with low stock
        $variantProducts = DB::table('branch_product_attribute as bpa')
            ->join('product_attributes as pa', 'pa.id', '=', 'bpa.product_attribute_id')
            ->join('products as p', 'p.id', '=', 'pa.product_id')
            ->where('bpa.branch_id', $branchId)
            ->where('bpa.current_stock', '<=', DB::raw('COALESCE(bpa.min_stock, 0)'))
            ->where('bpa.current_stock', '>', 0)
            ->select('p.id', 'p.name', 'p.sku', 'bpa.current_stock', 'bpa.min_stock')
            ->get()
            ->map(fn ($row) => [
                'id'            => $row->id,
                'name'          => $row->name,
                'sku'           => $row->sku,
                'current_stock' => (int) $row->current_stock,
                'min_stock'     => (int) ($row->min_stock ?? 0),
            ]);

        $all = $simpleProducts->merge($variantProducts)->take($threshold);

        return $all->toArray();
    }
}
