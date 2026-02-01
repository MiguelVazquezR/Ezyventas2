<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
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

        // CAMBIO: Incrementamos versión de caché para invalidar datos viejos y usar la nueva lógica
        $cacheKey = "dashboard_stats_branch_{$branchId}_v5";

        // --- 1. Ventas y Transacciones ---
        if ($isAdmin || $user->can('transactions.access')) {
            $startOfDay = now()->startOfDay();
            $endOfDay = now()->endOfDay();

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
                    ->where('status', ExpenseStatus::PAID)
                    ->sum('amount');
            });
        }
        
        if ($isAdmin || $user->can('financials.manage_cash_registers')) {
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

            // OPTIMIZADO: Nueva lógica de baja rotación
            $stats['low_turnover_products'] = Cache::remember("{$cacheKey}_low_turnover", 3600, function () use ($branchId) {
                return $this->getLowTurnoverProductsOptimized($branchId);
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

    /**
     * MÉTODO OPTIMIZADO PARA ALTO VOLUMEN DE DATOS
     * Evita subconsultas correlacionadas que causan timeouts.
     */
    private function getLowTurnoverProductsOptimized($branchId)
    {
        // Paso 1: Obtener la última fecha de venta de TODOS los items vendidos en esta sucursal de forma eficiente.
        // Esto usa índices y es una sola consulta agrupada.
        $soldItemsRaw = DB::table('transactions_items')
            ->join('transactions', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where('transactions.branch_id', $branchId)
            // Solo nos interesan las ventas válidas para calcular rotación
            ->whereNotIn('transactions.status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED])
            ->select('itemable_id', 'itemable_type', DB::raw('MAX(transactions.created_at) as last_sale'))
            ->groupBy('itemable_id', 'itemable_type')
            ->get();

        // Paso 2: Procesar en memoria (PHP es muy rápido para esto) para mapear Variantes -> Producto Padre
        $productLastSales = []; // [product_id => Carbon_date]
        
        // Obtenemos un mapa de VarianteID -> ProductoID para evitar queries N+1
        $variantIds = $soldItemsRaw->where('itemable_type', ProductAttribute::class)->pluck('itemable_id');
        $variantMap = DB::table('product_attributes')
            ->whereIn('id', $variantIds)
            ->pluck('product_id', 'id');

        foreach ($soldItemsRaw as $item) {
            $productId = null;
            if ($item->itemable_type === Product::class) {
                $productId = $item->itemable_id;
            } elseif ($item->itemable_type === ProductAttribute::class) {
                $productId = $variantMap[$item->itemable_id] ?? null;
            }

            if ($productId) {
                $saleDate = Carbon::parse($item->last_sale);
                // Si ya tenemos fecha para este producto, nos quedamos con la más reciente
                if (!isset($productLastSales[$productId]) || $saleDate->gt($productLastSales[$productId])) {
                    $productLastSales[$productId] = $saleDate;
                }
            }
        }

        // Paso 3: Encontrar productos de baja rotación
        // Prioridad A: Productos que NUNCA se han vendido
        $soldProductIds = array_keys($productLastSales);
        
        $lowTurnoverCollection = collect();

        // Buscamos hasta 5 productos que NO estén en la lista de vendidos
        $neverSoldProducts = Product::where('branch_id', $branchId)
            ->whereNotIn('id', $soldProductIds)
            ->with('media')
            ->limit(5)
            ->get()
            ->map(function ($p) {
                $p->virtual_last_sale_date = null; // Marcamos como nunca vendido
                return $p;
            });

        $lowTurnoverCollection = $lowTurnoverCollection->merge($neverSoldProducts);

        // Prioridad B: Si no completamos 5, buscamos los que tienen la fecha de venta más antigua
        if ($lowTurnoverCollection->count() < 5) {
            $needed = 5 - $lowTurnoverCollection->count();
            
            // Ordenamos el array de fechas ascendente (más viejas primero)
            asort($productLastSales);
            
            // Tomamos los IDs de los más viejos
            $oldestIds = array_slice(array_keys($productLastSales), 0, $needed);
            
            if (!empty($oldestIds)) {
                $oldSoldProducts = Product::whereIn('id', $oldestIds)
                    ->with('media')
                    ->get()
                    ->each(function($p) use ($productLastSales) {
                        $p->virtual_last_sale_date = $productLastSales[$p->id];
                    });
                
                // Reordenar porque el whereIn no garantiza orden
                $oldSoldProducts = $oldSoldProducts->sortBy(function($p) {
                    return $p->virtual_last_sale_date->timestamp;
                });

                $lowTurnoverCollection = $lowTurnoverCollection->merge($oldSoldProducts);
            }
        }

        // Paso 4: Formatear para la vista
        return $lowTurnoverCollection->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'selling_price' => (float)$product->selling_price,
                'current_stock' => $product->current_stock,
                'days_since_last_sale' => $product->virtual_last_sale_date 
                    ? $product->virtual_last_sale_date->diffInDays(now()) 
                    : null, // Null significa "Nunca vendido"
                'image' => $product->getFirstMediaUrl('product-general-images') ?: null,
            ];
        })->values(); // Resetear keys
    }

    private function getTopSellingProducts($branchId)
    {
        $topItems = DB::table('transactions_items')
            ->join('transactions', 'transactions_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.created_at', '>=', now()->startOfMonth())
            ->whereNotIn('transactions.status', [TransactionStatus::CANCELLED]) // Excluir canceladas
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
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->sum(DB::raw('subtotal - total_discount + total_tax'));
    }

    private function getWeeklySalesTrend($branchId): array
    {
        // 1. Definir la semana (Lunes a Domingo)
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = now()->endOfWeek(Carbon::SUNDAY);

        // 2. Consulta Robusta
        $trendData = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(total_discount) as total_discount'),
                DB::raw('SUM(total_tax) as total_tax')
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
                $subtotal = $dayData->total_subtotal ?? 0;
                $discount = $dayData->total_discount ?? 0;
                $tax = $dayData->total_tax ?? 0;
                
                $total = ($subtotal - $discount) + $tax;
            }

            $weekSales[] = [
                'day' => $date->translatedFormat('D'),
                'total' => (float) $total
            ];
        }
        
        return $weekSales;
    }
}