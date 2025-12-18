<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus; // Necesario para filtrar gastos pagados
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $isAdmin = !$user->roles()->exists();
        $stats = [];

        // Actualizamos la key del caché para forzar refresco con la nueva lógica (v2)
        $cacheKey = "dashboard_stats_branch_{$branchId}_v2";

        // --- 1. Ventas y Transacciones ---
        if ($isAdmin || $user->can('transactions.access')) {
            $startOfDay = now()->startOfDay();
            $endOfDay = now()->endOfDay();

            // CORRECCIÓN: Usar whereNotIn para excluir Canceladas y Cambiadas (consistente con Reportes)
            // CORRECCIÓN: Agregar '+ total_tax' al cálculo de ventas.
            $todayAggregates = Transaction::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED]) 
                ->selectRaw('SUM(subtotal - total_discount + total_tax) as total_sales')
                ->selectRaw('COUNT(*) as total_count')
                ->first();

            $totalSales = $todayAggregates->total_sales ?? 0;
            $transactionCount = $todayAggregates->total_count ?? 0;

            $stats['today_sales'] = (float) $totalSales;
            $stats['average_ticket_today'] = $transactionCount > 0 ? $totalSales / $transactionCount : 0;
            
            $stats['yesterday_sales'] = Cache::remember("{$cacheKey}_yesterday", 600, function () use ($branchId) {
                return $this->getSalesTotalForPeriod($branchId, today()->subDay(), today()->subDay());
            });

            $stats['weekly_sales_trend'] = Cache::remember("{$cacheKey}_weekly_trend", 3600, function () use ($branchId) {
                return $this->getWeeklySalesTrend($branchId);
            });

            // Conteo de Apartados por Vencer
            $stats['expiring_layaways_count'] = Transaction::where('branch_id', $branchId)
                ->where('status', TransactionStatus::ON_LAYAWAY)
                ->whereNotNull('layaway_expiration_date')
                ->whereDate('layaway_expiration_date', '<=', now()->addDays(5))
                ->count();
        }

        // --- 2. Control Financiero ---
        if ($isAdmin || $user->can('financials.access_dashboard')) {
            $stats['monthly_expenses'] = Cache::remember("{$cacheKey}_expenses", 3600, function () use ($branchId) {
                return Expense::where('branch_id', $branchId)
                    ->whereMonth('expense_date', now()->month)
                    ->whereYear('expense_date', now()->year)
                    ->where('status', ExpenseStatus::PAID) // CORRECCIÓN: Solo gastos pagados
                    ->sum('amount');
            });
        }
        
        if ($isAdmin || $user->can('financials.manage_cash_registers')) {
            // CORRECCIÓN: Renombramos alias a 'in_use_count' y 'available_count' 
            // para evitar conflicto con el cast 'boolean' del modelo CashRegister.
            $stats['cash_registers_status'] = CashRegister::where('branch_id', $branchId)
                ->where('is_active', true)
                ->selectRaw("COUNT(CASE WHEN in_use = 1 THEN 1 END) as in_use_count")
                ->selectRaw("COUNT(CASE WHEN in_use = 0 THEN 1 END) as available_count")
                ->first()
                ->toArray();
        }

        // --- 3. Productos e Inventario ---
        if ($isAdmin || $user->can('products.access')) {
            $stats['inventory_summary'] = Cache::remember("{$cacheKey}_inventory", 1800, function () use ($branchId) {
                return $this->getInventorySummaryOptimized($branchId);
            });

            $stats['top_selling_products'] = Cache::remember("{$cacheKey}_top_selling", 3600, function () use ($branchId) {
                return $this->getTopSellingProducts($branchId);
            });

            $stats['low_turnover_products'] = Cache::remember("{$cacheKey}_low_turnover", 3600, function () use ($branchId) {
                return $this->getLowTurnoverProducts($branchId);
            });
        }

        // --- 4. Servicios ---
        if ($isAdmin || $user->can('services.access_order')) {
            $stats['service_orders_status'] = ServiceOrder::where('branch_id', $branchId)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status');
        }

        // --- 5. Clientes ---
        if ($isAdmin || $user->can('customers.access')) {
            $stats['total_customer_debt'] = Cache::remember("{$cacheKey}_debt", 3600, function () use ($branchId) {
                return Customer::where('branch_id', $branchId)
                    ->where('balance', '<', 0)
                    ->sum('balance') * -1;
            });

            $stats['recent_customers'] = Customer::where('branch_id', $branchId)
                ->latest('id')
                ->limit(5)
                ->get(['id', 'name']);

            $stats['frequent_customers'] = Cache::remember("{$cacheKey}_frequent_cust", 3600, function () use ($branchId) {
                return Customer::where('branch_id', $branchId)
                    ->withCount(['transactions' => fn($q) => $q->whereMonth('created_at', now()->month)])
                    ->orderByDesc('transactions_count')
                    ->limit(5)
                    ->get(['id', 'name']);
            });
        }

        $userBankAccounts = $isAdmin 
            ? BankAccount::whereHas('branches', fn($q) => $q->where('branch_id', $branchId))->get() 
            : $user->bankAccounts()->get();
            
        $allSubscriptionBankAccounts = BankAccount::where('subscription_id', $user->branch->subscription_id)->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'userBankAccounts' => $userBankAccounts,
            'allSubscriptionBankAccounts' => $allSubscriptionBankAccounts,
        ]);
    }

    public function getExpiringLayaways(Request $request)
    {
        $user = Auth::user();
        
        $layaways = Transaction::where('branch_id', $user->branch_id)
            ->where('status', TransactionStatus::ON_LAYAWAY)
            ->whereNotNull('layaway_expiration_date')
            ->whereDate('layaway_expiration_date', '<=', now()->addDays(5))
            ->with('customer:id,name,phone')
            ->withSum('payments', 'amount')
            ->orderBy('layaway_expiration_date', 'asc')
            ->get()
            ->map(function ($t) {
                $totalPaid = $t->payments_sum_amount ?? 0;
                $total = (float) $t->total;
                
                return [
                    'id' => $t->id,
                    'folio' => $t->folio,
                    'customer_id' => $t->customer_id,
                    'customer_name' => $t->customer ? $t->customer->name : 'Público en General',
                    'customer_phone' => $t->customer?->phone,
                    'expiration_date' => $t->layaway_expiration_date->format('Y-m-d'),
                    'days_remaining' => number_format(now()->diffInDays($t->layaway_expiration_date, false), 0),
                    'total_amount' => $total,
                    'pending_amount' => max(0, $total - $totalPaid),
                ];
            });

        return response()->json($layaways);
    }

    private function getInventorySummaryOptimized($branchId)
    {
        $summary = DB::table('products')
            ->where('branch_id', $branchId)
            ->selectRaw('COUNT(*) as total_products')
            ->selectRaw('SUM(cost_price * current_stock) as total_cost')
            ->selectRaw('SUM(selling_price * current_stock) as total_sale_value')
            ->selectRaw('SUM(CASE WHEN current_stock > COALESCE(min_stock, 0) THEN 1 ELSE 0 END) as in_stock_count')
            ->selectRaw('SUM(CASE WHEN current_stock > 0 AND current_stock <= COALESCE(min_stock, 0) THEN 1 ELSE 0 END) as low_stock_count')
            ->selectRaw('SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock_count')
            ->first();

        if (!$summary || $summary->total_products == 0) {
            return null;
        }

        return [
            'total_cost' => (float) $summary->total_cost,
            'total_sale_value' => (float) $summary->total_sale_value,
            'total_products' => (int) $summary->total_products,
            'in_stock_count' => (int) $summary->in_stock_count,
            'low_stock_count' => (int) $summary->low_stock_count,
            'out_of_stock_count' => (int) $summary->out_of_stock_count,
        ];
    }

    private function getLowTurnoverProducts($branchId)
    {
        $thresholdDate = now()->subDays(15);
        $lastSaleSubquery = DB::table('transactions_items')
            ->join('transactions', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where('transactions.branch_id', $branchId)
            ->whereRaw("((transactions_items.itemable_type = ? AND transactions_items.itemable_id = products.id) OR (transactions_items.itemable_type = ? AND transactions_items.itemable_id IN (SELECT id FROM product_attributes WHERE product_id = products.id)))", [Product::class, ProductAttribute::class])
            ->selectRaw('MAX(transactions.created_at)');

        return Product::where('branch_id', $branchId)
            ->select('id', 'name', 'selling_price', 'current_stock')
            ->selectSub($lastSaleSubquery, 'last_sale_date')
            ->with('media')
            ->having('last_sale_date', '<', $thresholdDate)
            ->orHavingNull('last_sale_date')
            ->orderBy('last_sale_date', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'selling_price' => (float)$product->selling_price,
                    'current_stock' => $product->current_stock,
                    'days_since_last_sale' => $product->last_sale_date ? Carbon::parse($product->last_sale_date)->diffInDays(now()) : null,
                    'image' => $product->getFirstMediaUrl('product-general-images') ?: null,
                ];
            });
    }

    private function getTopSellingProducts($branchId)
    {
        $topItems = DB::table('transactions_items')
            ->join('transactions', 'transactions_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.created_at', '>=', now()->startOfMonth())
            ->select('itemable_id', 'itemable_type', DB::raw('SUM(quantity) as total_sold'))
            ->groupBy('itemable_id', 'itemable_type')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        if ($topItems->isEmpty()) return collect();

        $productIds = $topItems->where('itemable_type', Product::class)->pluck('itemable_id');
        $attributeIds = $topItems->where('itemable_type', ProductAttribute::class)->pluck('itemable_id');

        $products = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');
        $attributes = ProductAttribute::with(['product.media'])->whereIn('id', $attributeIds)->get()->keyBy('id');

        return $topItems->map(function ($item) use ($products, $attributes) {
            $data = null;
            if ($item->itemable_type === Product::class && $product = $products->get($item->itemable_id)) {
                $data = ['id' => $product->id, 'name' => $product->name, 'variant_description' => null, 'selling_price' => (float)$product->selling_price, 'image' => $product->getFirstMediaUrl('product-general-images')];
            } elseif ($item->itemable_type === ProductAttribute::class && $attr = $attributes->get($item->itemable_id)) {
                if ($attr->product) {
                    $desc = collect($attr->attributes)->map(fn($v, $k) => Str::ucfirst($k) . ': ' . $v)->implode(' / ');
                    $data = ['id' => $attr->product->id, 'name' => $attr->product->name, 'variant_description' => $desc, 'selling_price' => (float)($attr->product->selling_price + $attr->selling_price_modifier), 'image' => $attr->product->getFirstMediaUrl('product-general-images')];
                }
            }
            if ($data) {
                $data['total_sold'] = (int)$item->total_sold;
                return $data;
            }
            return null;
        })->filter()->values();
    }

    private function getSalesTotalForPeriod($branchId, Carbon $start, Carbon $end): float
    {
        return Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            // CORRECCIÓN: Usar whereNotIn y sumar impuestos
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->sum(DB::raw('subtotal - total_discount + total_tax'));
    }

    private function getWeeklySalesTrend($branchId): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = now()->endOfWeek(Carbon::SUNDAY);

        $trendData = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            // CORRECCIÓN: Usar whereNotIn y sumar impuestos
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(subtotal - total_discount + total_tax) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $weekSales = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateString = $date->format('Y-m-d');
            $weekSales[] = ['day' => $date->translatedFormat('D'), 'total' => (float) ($trendData[$dateString] ?? 0)];
        }
        return $weekSales;
    }
}