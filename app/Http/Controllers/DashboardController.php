<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Promotion;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Str;

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

        // --- Panel de Cuentas Bancarias ---
        $userBankAccounts = null;
        $allSubscriptionBankAccounts = null;

        if ($isAdmin) {
            $userBankAccounts = BankAccount::whereHas('branches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })->get();
        } else {
            $userBankAccounts = $user->bankAccounts()->get();
        }
        // Para el modal de transferencias, se necesitan todas las cuentas de la suscripción.
        $allSubscriptionBankAccounts = BankAccount::where('subscription_id', $user->branch->subscription_id)->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'userBankAccounts' => $userBankAccounts,
            'allSubscriptionBankAccounts' => $allSubscriptionBankAccounts,
        ]);
    }

    /**
     * Obtiene los 5 productos con 15 o más días sin ventas, considerando también las ventas de sus variantes.
     */
    private function getLowTurnoverProducts($branchId)
    {
        $thresholdDate = now()->subDays(15);

        // --- CORRECCIÓN: La subconsulta ahora busca ventas del producto base O de cualquiera de sus variantes. ---
        $lastSaleSubquery = Transaction::select('transactions.created_at')
            ->join('transactions_items', 'transactions.id', '=', 'transactions_items.transaction_id')
            ->where(function ($query) {
                // Condición 1: El item vendido es el producto base.
                $query->where('transactions_items.itemable_type', Product::class)
                    ->whereColumn('transactions_items.itemable_id', 'products.id');
            })
            ->orWhere(function ($query) {
                // Condición 2: El item vendido es una de las variantes de este producto.
                $query->where('transactions_items.itemable_type', ProductAttribute::class)
                    ->whereIn('transactions_items.itemable_id', function ($subQuery) {
                        $subQuery->select('id')
                            ->from('product_attributes')
                            ->whereColumn('product_attributes.product_id', 'products.id');
                    });
            })
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

    /**
     * Obtiene los 5 productos más vendidos, sumando las ventas de productos base y sus variantes.
     */
    private function getTopSellingProducts($branchId)
    {
        // --- CORRECCIÓN: La lógica se reestructura para identificar el itemable (producto o variante) más vendido. ---
        $topItems = DB::table('transactions_items')
            ->join('transactions', 'transactions_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.branch_id', $branchId)
            ->whereBetween('transactions.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->select('itemable_id', 'itemable_type', DB::raw('SUM(quantity) as total_sold'))
            ->groupBy('itemable_id', 'itemable_type')
            ->orderByDesc('total_sold')
            ->limit(5)
            ->get();

        if ($topItems->isEmpty()) {
            return collect();
        }

        // Separar IDs por tipo
        $productIds = $topItems->where('itemable_type', Product::class)->pluck('itemable_id');
        $attributeIds = $topItems->where('itemable_type', ProductAttribute::class)->pluck('itemable_id');

        // Cargar los modelos necesarios con sus relaciones
        $products = Product::with('media')->whereIn('id', $productIds)->get()->keyBy('id');
        $attributes = ProductAttribute::with(['product.media'])->whereIn('id', $attributeIds)->get()->keyBy('id');

        // Unir y formatear los resultados
        return $topItems->map(function ($item) use ($products, $attributes) {
            if ($item->itemable_type === Product::class) {
                $product = $products->get($item->itemable_id);
                if (!$product) return null;

                return [
                    'id' => $product->id, // ID del producto padre para el enlace
                    'name' => $product->name,
                    'variant_description' => null, // No es una variante
                    'selling_price' => (float)$product->selling_price,
                    'total_sold' => (int)$item->total_sold,
                    'image' => $product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/100x100?text=' . urlencode($product->name),
                ];
            }

            if ($item->itemable_type === ProductAttribute::class) {
                $attribute = $attributes->get($item->itemable_id);
                if (!$attribute || !$attribute->product) return null;

                // Formatear la descripción de la variante
                $variantDescription = collect($attribute->attributes)->map(function ($value, $key) {
                    return Str::ucfirst($key) . ': ' . $value;
                })->implode(' / ');

                return [
                    'id' => $attribute->product->id, // ID del producto padre para el enlace
                    'name' => $attribute->product->name,
                    'variant_description' => $variantDescription,
                    'selling_price' => (float)($attribute->product->selling_price + $attribute->selling_price_modifier),
                    'total_sold' => (int)$item->total_sold,
                    'image' => $attribute->product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/100x100?text=' . urlencode($attribute->product->name),
                ];
            }

            return null;
        })->filter()->sortByDesc('total_sold')->values(); // Limpiar nulos, reordenar por si acaso y resetear keys
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

    /**
     * Obtiene la tendencia de ventas de la semana actual.
     * --- CORREGIDO ---
     */
    private function getWeeklySalesTrend($branchId): array
    {
        // Se obtienen las sumas de subtotal y descuento por separado para evitar errores con NULL.
        $trendData = Transaction::where('branch_id', $branchId)
            // CORRECCIÓN: La semana ahora comienza en Lunes y termina en Domingo.
            ->whereBetween('created_at', [now()->startOfWeek(Carbon::MONDAY), now()->endOfWeek(Carbon::SUNDAY)])
            ->where('status', 'completado')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(total_discount) as total_discounts')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->keyBy('date'); // Se agrupa por fecha para una búsqueda fácil.

        $weekSales = [];
        for ($i = 0; $i < 7; $i++) {
            // CORRECCIÓN: El ciclo ahora genera los días de Lunes a Domingo.
            $date = now()->startOfWeek(Carbon::MONDAY)->addDays($i);
            $dateString = $date->format('Y-m-d');

            $dayData = $trendData->get($dateString);

            $total = 0;
            if ($dayData) {
                // Se realiza el cálculo en PHP para mayor seguridad.
                $total = ($dayData->total_subtotal ?? 0) - ($dayData->total_discounts ?? 0);
            }

            $weekSales[] = [
                'day' => $date->translatedFormat('D'),
                'total' => (float) $total
            ];
        }
        return $weekSales;
    }
}
