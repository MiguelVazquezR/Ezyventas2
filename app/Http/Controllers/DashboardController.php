<?php

namespace App\Http\Controllers;

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

        // Clave de caché única por sucursal
        $cacheKey = "dashboard_stats_branch_{$branchId}";

        // --- 1. Ventas y Transacciones (Datos en tiempo real, sin caché larga) ---
        if ($isAdmin || $user->can('transactions.access')) {
            // Optimización: Usar whereBetween aprovecha índices en created_at mejor que whereDate
            $startOfDay = now()->startOfDay();
            $endOfDay = now()->endOfDay();

            // Hacemos una sola consulta agregada para hoy
            $todayAggregates = Transaction::where('branch_id', $branchId)
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->where('status', 'completado')
                ->selectRaw('SUM(subtotal - total_discount) as total_sales')
                ->selectRaw('COUNT(*) as total_count')
                ->first();

            $totalSales = $todayAggregates->total_sales ?? 0;
            $transactionCount = $todayAggregates->total_count ?? 0;

            $stats['today_sales'] = (float) $totalSales;
            $stats['average_ticket_today'] = $transactionCount > 0 ? $totalSales / $transactionCount : 0;
            
            // Ventas de ayer (Cache 10 min)
            $stats['yesterday_sales'] = Cache::remember("{$cacheKey}_yesterday", 600, function () use ($branchId) {
                return $this->getSalesTotalForPeriod($branchId, today()->subDay(), today()->subDay());
            });

            // Tendencia semanal (Cache 1 hora)
            $stats['weekly_sales_trend'] = Cache::remember("{$cacheKey}_weekly_trend", 3600, function () use ($branchId) {
                return $this->getWeeklySalesTrend($branchId);
            });
        }

        // --- 2. Control Financiero ---
        if ($isAdmin || $user->can('financials.access_dashboard')) {
            $stats['monthly_expenses'] = Cache::remember("{$cacheKey}_expenses", 3600, function () use ($branchId) {
                return Expense::where('branch_id', $branchId)
                    ->whereMonth('expense_date', now()->month)
                    ->whereYear('expense_date', now()->year)
                    ->sum('amount');
            });
        }
        
        if ($isAdmin || $user->can('financials.manage_cash_registers')) {
            // Consulta ligera, no requiere caché estricta pero ayuda
            $stats['cash_registers_status'] = CashRegister::where('branch_id', $branchId)
                ->where('is_active', true)
                ->selectRaw("COUNT(CASE WHEN in_use = 1 THEN 1 END) as in_use")
                ->selectRaw("COUNT(CASE WHEN in_use = 0 THEN 1 END) as available")
                ->first()
                ->toArray();
        }

        // --- 3. Productos e Inventario (LA PARTE MÁS PESADA) ---
        if ($isAdmin || $user->can('products.access')) {
            // Cacheamos estos reportes pesados por 30-60 minutos
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
                ->pluck('total', 'status.value'); // Pluck ejecuta la query inmediatamente
        }

        // --- 5. Clientes ---
        if ($isAdmin || $user->can('customers.access')) {
            // Deuda total (Cache 1 hora, es un cálculo pesado de suma total)
            $stats['total_customer_debt'] = Cache::remember("{$cacheKey}_debt", 3600, function () use ($branchId) {
                return Customer::where('branch_id', $branchId)
                    ->where('balance', '<', 0)
                    ->sum('balance') * -1;
            });

            $stats['recent_customers'] = Customer::where('branch_id', $branchId)
                ->latest('id') // Optimización: latest id es más rápido que latest created_at si es autoincrement
                ->limit(5)
                ->get(['id', 'name']);

            // Clientes frecuentes (Cache 1 hora)
            $stats['frequent_customers'] = Cache::remember("{$cacheKey}_frequent_cust", 3600, function () use ($branchId) {
                return Customer::where('branch_id', $branchId)
                    ->withCount(['transactions' => fn($q) => $q->whereMonth('created_at', now()->month)])
                    ->orderByDesc('transactions_count') // Ordenar antes del having puede ser más rápido en algunos motores SQL
                    ->limit(5)
                    ->get(['id', 'name']);
            });
        }

        // --- Cuentas Bancarias ---
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

    /**
     * OPTIMIZADO: Calcula el inventario usando SQL puro en lugar de hidratar miles de modelos.
     */
    private function getInventorySummaryOptimized($branchId)
    {
        // Una sola consulta para obtener todos los agregados
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

        // Subconsulta optimizada: solo trae el max created_at
        $lastSaleSubquery = DB::table('transactions_items')
            ->join('transactions', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where('transactions.branch_id', $branchId) // Filtro de branch en la transacción
            ->whereRaw("(
                (transactions_items.itemable_type = ? AND transactions_items.itemable_id = products.id)
                OR 
                (transactions_items.itemable_type = ? AND transactions_items.itemable_id IN (
                    SELECT id FROM product_attributes WHERE product_id = products.id
                ))
            )", [Product::class, ProductAttribute::class])
            ->selectRaw('MAX(transactions.created_at)');

        return Product::where('branch_id', $branchId)
            ->select('id', 'name', 'selling_price', 'current_stock')
            ->selectSub($lastSaleSubquery, 'last_sale_date')
            ->with('media') // Eager load media
            ->having('last_sale_date', '<', $thresholdDate)
            ->orHavingNull('last_sale_date')
            ->orderBy('last_sale_date', 'asc') // Los que nunca se vendieron o hace más tiempo primero
            ->limit(5)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'selling_price' => (float)$product->selling_price,
                    'current_stock' => $product->current_stock,
                    'days_since_last_sale' => $product->last_sale_date ? Carbon::parse($product->last_sale_date)->diffInDays(now()) : null,
                    'image' => $product->getFirstMediaUrl('product-general-images') ?: null, // Quitamos placeholder para aligerar JSON
                ];
            });
    }

    private function getTopSellingProducts($branchId)
    {
        $topItems = DB::table('transactions_items')
            ->join('transactions', 'transactions_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.created_at', '>=', now()->startOfMonth()) // Índice en created_at es vital
            ->select('itemable_id', 'itemable_type', DB::raw('SUM(quantity) as total_sold'))
            ->groupBy('itemable_id', 'itemable_type')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        if ($topItems->isEmpty()) return collect();

        // Carga eficiente de modelos
        $productIds = $topItems->where('itemable_type', Product::class)->pluck('itemable_id');
        $attributeIds = $topItems->where('itemable_type', ProductAttribute::class)->pluck('itemable_id');

        $products = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');
        $attributes = ProductAttribute::with(['product.media'])->whereIn('id', $attributeIds)->get()->keyBy('id');

        return $topItems->map(function ($item) use ($products, $attributes) {
            $data = null;
            
            if ($item->itemable_type === Product::class && $product = $products->get($item->itemable_id)) {
                $data = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'variant_description' => null,
                    'selling_price' => (float)$product->selling_price,
                    'image' => $product->getFirstMediaUrl('product-general-images'),
                ];
            } elseif ($item->itemable_type === ProductAttribute::class && $attr = $attributes->get($item->itemable_id)) {
                if ($attr->product) {
                    $desc = collect($attr->attributes)->map(fn($v, $k) => Str::ucfirst($k) . ': ' . $v)->implode(' / ');
                    $data = [
                        'id' => $attr->product->id,
                        'name' => $attr->product->name,
                        'variant_description' => $desc,
                        'selling_price' => (float)($attr->product->selling_price + $attr->selling_price_modifier),
                        'image' => $attr->product->getFirstMediaUrl('product-general-images'),
                    ];
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
        // whereBetween usa índices, whereDate no.
        return Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start->startOfDay(), $end->endOfDay()])
            ->where('status', 'completado')
            ->sum(DB::raw('subtotal - total_discount'));
    }

    private function getWeeklySalesTrend($branchId): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = now()->endOfWeek(Carbon::SUNDAY);

        $trendData = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->where('status', 'completado')
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('SUM(subtotal - total_discount) as total')
            ->groupBy('date')
            ->pluck('total', 'date'); // Retorna array [ '2023-10-01' => 500, ... ]

        $weekSales = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateString = $date->format('Y-m-d');
            
            $weekSales[] = [
                'day' => $date->translatedFormat('D'),
                'total' => (float) ($trendData[$dateString] ?? 0)
            ];
        }
        return $weekSales;
    }
}