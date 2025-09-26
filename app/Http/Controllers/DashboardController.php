<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $stats = [];
        $isAdmin = !$user->roles()->exists();

        // --- Ventas y Transacciones ---
        if ($isAdmin || $user->can('transactions.access')) {
            $todayTransactions = Transaction::where('branch_id', $branchId)->whereDate('created_at', today())->where('status', 'completado');
            $totalSales = (clone $todayTransactions)->sum(DB::raw('subtotal - total_discount'));
            $transactionCount = (clone $todayTransactions)->count();

            $stats['today_sales'] = $totalSales;
            $stats['average_ticket_today'] = $transactionCount > 0 ? $totalSales / $transactionCount : 0;
            $stats['yesterday_sales'] = $this->getSalesTotalForPeriod($branchId, today()->subDay(), today()->subDay());
            $stats['weekly_sales_trend'] = $this->getWeeklySalesTrend($branchId);
        }

        // --- Control Financiero ---
        if ($isAdmin || $user->can('financials.access_dashboard')) {
            $stats['monthly_expenses'] = Expense::where('branch_id', $branchId)
                ->whereMonth('expense_date', now()->month)
                ->whereYear('expense_date', now()->year)
                ->sum('amount');
        }
        if ($isAdmin || $user->can('financials.manage_cash_registers')) {
            $cashRegisters = CashRegister::where('branch_id', $branchId)->where('is_active', true)->get();
            $stats['cash_registers_status'] = [
                'in_use' => $cashRegisters->where('in_use', true)->count(),
                'available' => $cashRegisters->where('in_use', false)->count(),
            ];
        }

        // --- Productos e Inventario ---
        if ($isAdmin || $user->can('products.access')) {
            $stats['top_selling_products'] = $this->getTopSellingProducts($branchId);
            $stats['inventory_summary'] = $this->getInventorySummary($branchId);
            $stats['low_turnover_products'] = $this->getLowTurnoverProducts($branchId);
        }

        // --- Servicios ---
        if ($isAdmin || $user->can('services.access_order')) {
            $stats['service_orders_status'] = ServiceOrder::where('branch_id', $branchId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')->get()->pluck('total', 'status.value');
        }

        // --- Clientes ---
        if ($isAdmin || $user->can('customers.access')) {
            $stats['total_customer_debt'] = Customer::where('branch_id', $branchId)
                ->where('balance', '<', 0)->sum('balance') * -1;
            $stats['recent_customers'] = Customer::where('branch_id', $branchId)->latest()->limit(5)->get(['id', 'name']);
            $stats['frequent_customers'] = Customer::where('branch_id', $branchId)
                ->withCount(['transactions' => fn($q) => $q->whereMonth('created_at', now()->month)])
                ->having('transactions_count', '>', 0)
                ->orderByDesc('transactions_count')->limit(5)->get(['id', 'name', 'transactions_count']);
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats
        ]);
    }

    /**
     * Obtiene los 5 productos con 15 o más días sin ventas.
     */
    private function getLowTurnoverProducts($branchId)
    {
        $thresholdDate = now()->subDays(15);

        // SOLUCIÓN: Especificar la tabla 'transactions.created_at' para evitar ambigüedad.
        $lastSaleSubquery = Transaction::select('transactions.created_at')
            ->join('transactions_items', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where('transactions_items.itemable_type', Product::class)
            ->whereColumn('transactions_items.itemable_id', 'products.id')
            ->latest('transactions.created_at')
            ->limit(1);

        $lowTurnoverProducts = Product::where('branch_id', $branchId)
            ->select('id', 'name', 'selling_price', 'current_stock')
            ->selectSub($lastSaleSubquery, 'last_sale_date')
            ->with('media')
            ->having('last_sale_date', '<', $thresholdDate)
            ->orHavingNull('last_sale_date')
            ->orderBy('last_sale_date', 'asc')
            ->limit(5)
            ->get();

        return $lowTurnoverProducts->map(function ($product) {
            $daysSinceSale = $product->last_sale_date ? Carbon::parse($product->last_sale_date)->diffInDays(now()) : null;
            return [
                'id' => $product->id,
                'name' => $product->name,
                'selling_price' => (float)$product->selling_price,
                'current_stock' => $product->current_stock,
                'days_since_last_sale' => $daysSinceSale,
                'image' => $product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/100x100?text=' . urlencode($product->name),
            ];
        });
    }

    private function getTopSellingProducts($branchId)
    {
        $topProductsStats = Transaction::join('transactions_items', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->join('products', 'transactions_items.itemable_id', '=', 'products.id')
            ->where('transactions_items.itemable_type', Product::class)
            ->where('transactions.branch_id', $branchId)
            ->whereBetween('transactions.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->select(
                'products.id',
                'products.name',
                'products.selling_price',
                DB::raw('SUM(transactions_items.quantity) as total_sold')
            )
            ->groupBy('products.id', 'products.name', 'products.selling_price')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        $productIds = $topProductsStats->pluck('id');
        if ($productIds->isEmpty()) {
            return collect();
        }

        $productsWithMedia = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');

        return $topProductsStats->map(function ($stat) use ($productsWithMedia) {
            $product = $productsWithMedia->get($stat->id);
            return [
                'id' => $stat->id,
                'name' => $stat->name,
                'selling_price' => (float)$stat->selling_price,
                'total_sold' => (int)$stat->total_sold,
                'image' => $product ? ($product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/100x100?text=' . urlencode($stat->name)) : null,
            ];
        });
    }

    private function getInventorySummary($branchId)
    {
        $products = Product::where('branch_id', $branchId)->get();
        $totalProducts = $products->count();
        if ($totalProducts === 0) {
            return null;
        }

        $totalCost = $products->sum(fn($p) => $p->cost_price * $p->current_stock);
        $totalSaleValue = $products->sum(fn($p) => $p->selling_price * $p->current_stock);

        $inStock = $products->filter(fn($p) => $p->current_stock > ($p->min_stock ?? 0))->count();
        $lowStock = $products->filter(fn($p) => $p->current_stock > 0 && $p->current_stock <= ($p->min_stock ?? 0))->count();
        $outOfStock = $totalProducts - $inStock - $lowStock;

        return [
            'total_cost' => $totalCost,
            'total_sale_value' => $totalSaleValue,
            'total_products' => $totalProducts,
            'in_stock_count' => $inStock,
            'low_stock_count' => $lowStock,
            'out_of_stock_count' => $outOfStock,
        ];
    }

    private function getSalesTotalForPeriod($branchId, Carbon $start, Carbon $end): float
    {
        return Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->where('status', 'completado')
            ->sum(DB::raw('subtotal - total_discount'));
    }

    private function getWeeklySalesTrend($branchId): array
    {
        $trend = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [now()->startOfWeek(Carbon::SUNDAY), now()->endOfWeek(Carbon::SATURDAY)])
            ->where('status', 'completado')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(subtotal - total_discount) as total'))
            ->groupBy('date')->orderBy('date', 'asc')->pluck('total', 'date')->toArray();

        $weekSales = [];
        for ($i = 0; $i < 7; $i++) {
            $date = now()->startOfWeek(Carbon::SUNDAY)->addDays($i);
            $dateString = $date->format('Y-m-d');
            $weekSales[] = ['day' => $date->translatedFormat('D'), 'total' => (float)($trend[$dateString] ?? 0)];
        }
        return $weekSales;
    }
}
