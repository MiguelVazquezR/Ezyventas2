<?php

namespace App\AiTools;

use App\AiTools\SiteNavigationRegistry;
use App\Actions\Expense\StoreExpenseAction;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Services\CashRegisterReportService;
use App\Services\CustomerReportService;
use App\Services\ExpenseReportService;
use App\Services\FinancialReportService;
use App\Services\InventoryReportService;
use App\Services\PromotionReportService;
use App\Services\QuoteInvoiceReportService;
use App\Services\SalesDashboardService;
use App\Services\ServiceOrderReportService;
use App\Services\StaffPerformanceService;
use App\Services\TransactionQueryService;
use Carbon\Carbon;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;
use Prism\Prism\Tool;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(function ($def) use ($user) {
                $perms = (array) ($def['permission'] ?? []);
                return empty($perms) || collect($perms)->every(fn ($p) => $user->can($p));
            })
            ->map(fn ($def) => $def['tool'])
            ->values()
            ->all();
    }

    /**
     * Return the list of available category labels for the user's permitted tools.
     */
    public function categories(Authenticatable $user): array
    {
        return collect($this->definitions($user))
            ->filter(function ($def) use ($user) {
                $perms = (array) ($def['permission'] ?? []);
                return empty($perms) || collect($perms)->every(fn ($p) => $user->can($p));
            })
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * All tool definitions with permission gates and category metadata.
     * Each closure derives tenant scoping from $user server-side.
     */
    private function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;
        $subscriptionId = $user->branch->subscription_id;

        return [
            // ════════════════ REPORTS ════════════════
            [
                'permission' => 'financial_reports.access',
                'category'   => 'financial reports',
                'tool'       => (new Tool)->as('financial_report')
                    ->for('Obtener KPIs financieros, ventas por canal, gastos por categoría, distribución de ventas por hora (cuando el rango es un solo día), gráfica de tendencia y resumen de bancos para un rango de fechas determinado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $service = new FinancialReportService(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        $data = $service->generateReportData();
                        return json_encode($data, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.access',
                'category'   => 'inventory',
                'tool'       => (new Tool)->as('inventory_dead_stock')
                    ->for('Listar productos que NO han tenido ventas en los últimos N días (inventario muerto)')
                    ->withNumberParameter('days', 'Días sin movimiento, ej. 30, 60, 90')
                    ->withStringParameter('category_id', 'ID de categoría (opcional, usar null para todas)')
                    ->using(function (int $days, ?string $category_id = null) use ($branchId) {
                        $categoryId = $category_id && $category_id !== 'null' ? (int) $category_id : null;
                        $result = app(InventoryReportService::class)->deadStock(
                            $branchId, Carbon::now()->subDays($days), Carbon::now(), $categoryId,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ SALES ════════════════
            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('recent_transactions')
                    ->for('Obtener las transacciones más recientes de una sucursal')
                    ->withNumberParameter('limit', 'Cantidad máxima de transacciones (máx 20)')
                    ->using(function (int $limit = 10) use ($branchId) {
                        $result = app(TransactionQueryService::class)->search($branchId, [
                            'limit' => min($limit, 20),
                        ]);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'transactions.access',
                'category'   => 'transactions',
                'tool'       => (new Tool)->as('search_transactions')
                    ->for('Buscar transacciones con filtros: estado, método de pago, canal y rango de fechas')
                    ->withStringParameter('status', 'Estado de la transacción (completado, pendiente, cancelado, etc.) o null')
                    ->withStringParameter('payment_method', 'Método de pago (efectivo, tarjeta, transferencia) o null')
                    ->withStringParameter('date_from', 'Fecha inicial YYYY-MM-DD o null')
                    ->withStringParameter('date_to', 'Fecha final YYYY-MM-DD o null')
                    ->withStringParameter('channel', 'Canal de venta (tienda, en_linea) o null')
                    ->using(function (?string $status = null, ?string $payment_method = null, ?string $date_from = null, ?string $date_to = null, ?string $channel = null) use ($branchId) {
                        $filters = array_filter([
                            'status'         => $status,
                            'payment_method' => $payment_method,
                            'date_from'      => $date_from,
                            'date_to'        => $date_to,
                            'channel'        => $channel,
                        ], fn ($v) => $v !== null && $v !== 'null');
                        $result = app(TransactionQueryService::class)->search($branchId, $filters);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CUSTOMERS ════════════════
            [
                'permission' => 'customers.access',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('search_customers')
                    ->for('Buscar clientes por nombre, email o teléfono')
                    ->withStringParameter('query', 'Nombre parcial, email o teléfono a buscar')
                    ->using(function (string $query) use ($branchId) {
                        $customers = Customer::query()
                            ->where('branch_id', $branchId)
                            ->where(function ($q) use ($query) {
                                $q->where('name', 'LIKE', "%{$query}%")
                                  ->orWhere('email', 'LIKE', "%{$query}%")
                                  ->orWhere('phone', 'LIKE', "%{$query}%");
                            })
                            ->limit(15)
                            ->get(['id', 'name', 'email', 'phone', 'balance', 'credit_limit']);
                        return json_encode($customers, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_purchase_history')
                    ->for('Obtener el historial de compras recientes de un cliente específico')
                    ->withStringParameter('customer_query', 'Nombre, email o teléfono del cliente a buscar')
                    ->using(function (string $customer_query) use ($branchId) {
                        $customer = Customer::where('branch_id', $branchId)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$customer_query}%")
                                ->orWhere('email', 'like', "%{$customer_query}%")
                                ->orWhere('phone', 'like', "%{$customer_query}%"))
                            ->firstOrFail();
                        $result = app(CustomerReportService::class)->getPurchaseHistory($branchId, $customer->id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_account_statement')
                    ->for('Obtener el estado de cuenta y movimientos de saldo de un cliente')
                    ->withStringParameter('customer_query', 'Nombre, email o teléfono del cliente a buscar')
                    ->using(function (string $customer_query) use ($branchId) {
                        $customer = Customer::where('branch_id', $branchId)
                            ->where(fn ($q) => $q->where('name', 'like', "%{$customer_query}%")
                                ->orWhere('email', 'like', "%{$customer_query}%")
                                ->orWhere('phone', 'like', "%{$customer_query}%"))
                            ->firstOrFail();
                        $result = app(CustomerReportService::class)->getAccountStatement($branchId, $customer->id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('top_customers')
                    ->for('Obtener el ranking de los clientes con mayor gasto en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->withNumberParameter('limit', 'Cantidad máxima de clientes (por defecto 10)')
                    ->using(function (string $start_date, string $end_date, ?int $limit = 10) use ($branchId) {
                        $result = app(CustomerReportService::class)->getTopCustomers(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            $limit ?? 10,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.see_financial_info',
                'category'   => 'customers',
                'tool'       => (new Tool)->as('customer_recency')
                    ->for('Listar clientes que no han comprado en los últimos N días, o que compraron recientemente, para identificar clientes inactivos')
                    ->withNumberParameter('days', 'Días de inactividad o ventana reciente, ej. 30, 60, 90')
                    ->withStringParameter('direction', '"inactive" (no han comprado en N días) o "recent" (compraron dentro de N días). Por defecto "inactive"')
                    ->withNumberParameter('limit', 'Cantidad máxima de clientes (por defecto 20, máximo 50)')
                    ->using(function (int $days, ?string $direction = 'inactive', ?int $limit = 20) use ($branchId) {
                        $result = app(CustomerReportService::class)->getCustomerRecency(
                            $branchId,
                            $days,
                            $direction ?? 'inactive',
                            min($limit ?? 20, 50),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ PRODUCTS ════════════════
            [
                'permission' => 'products.access',
                'category'   => 'products',
                'tool'       => (new Tool)->as('search_products')
                    ->for('Buscar productos por nombre o SKU')
                    ->withStringParameter('query', 'Nombre parcial o SKU del producto')
                    ->using(function (string $query) use ($branchId) {
                        $products = Product::query()
                            ->where('branch_id', $branchId)
                            ->where(function ($q) use ($query) {
                                $q->where('name', 'LIKE', "%{$query}%")
                                  ->orWhere('sku', 'LIKE', "%{$query}%");
                            })
                            ->with('category:id,name')
                            ->limit(15)
                            ->get(['id', 'name', 'sku', 'selling_price', 'cost_price', 'category_id']);
                        return json_encode($products, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'dashboard.see_inventory_details',
                'category'   => 'inventory',
                'tool'       => (new Tool)->as('low_stock_products')
                    ->for('Listar productos con stock bajo o por debajo del mínimo')
                    ->withNumberParameter('threshold', 'Cantidad máxima de productos a devolver (por defecto 5)')
                    ->using(function (?int $threshold = 5) use ($branchId) {
                        $result = app(SalesDashboardService::class)->getLowStockProducts($branchId, $threshold ?? 5);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.access',
                'category'   => 'products',
                'tool'       => (new Tool)->as('sales_by_product')
                    ->for('Obtener las ventas agrupadas por producto o categoría en un período, ordenadas por monto o cantidad vendida')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->withStringParameter('group_by', '"product" o "category" (por defecto "product")')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 10, máximo 50)')
                    ->using(function (string $start_date, string $end_date, ?string $group_by = 'product', ?int $limit = 10) use ($branchId) {
                        $result = app(InventoryReportService::class)->salesByProduct(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            $group_by ?? 'product',
                            min($limit ?? 10, 50),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'financial_reports.access',
                'category'   => 'products',
                'tool'       => (new Tool)->as('product_margin_report')
                    ->for('Obtener el margen de ganancia por producto en un período, calculado como (precio de venta - costo) × cantidad vendida')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 10, máximo 50)')
                    ->withStringParameter('sort', '"margin_amount" o "margin_percent" (por defecto "margin_amount")')
                    ->using(function (string $start_date, string $end_date, ?int $limit = 10, ?string $sort = 'margin_amount') use ($branchId) {
                        $result = app(InventoryReportService::class)->productMarginReport(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            min($limit ?? 10, 50),
                            $sort ?? 'margin_amount',
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CASH REGISTER ════════════════
            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_session_summary')
                    ->for('Obtener el resumen de una sesión de caja: totales por método de pago y conciliación bancaria')
                    ->withNumberParameter('session_id', 'ID de la sesión de caja registradora')
                    ->using(function (int $session_id) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getSessionSummary($branchId, $session_id);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('cash_register_discrepancies')
                    ->for('Listar sesiones de caja con discrepancias entre el efectivo contado y el esperado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getDiscrepancies(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'cash_registers.sessions.access',
                'category'   => 'cash register sessions',
                'tool'       => (new Tool)->as('daily_cash_close')
                    ->for('Obtener el cierre de caja de una fecha específica')
                    ->withStringParameter('date', 'Fecha en formato YYYY-MM-DD')
                    ->using(function (string $date) use ($branchId) {
                        $result = app(CashRegisterReportService::class)->getDailyClose($branchId, $date);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ PROMOTIONS ════════════════
            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('active_promotions')
                    ->for('Listar las promociones actualmente activas')
                    ->using(function () use ($branchId) {
                        $result = app(PromotionReportService::class)->getActivePromotions($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.manage_promos',
                'category'   => 'promotions',
                'tool'       => (new Tool)->as('promotion_usage_stats')
                    ->for('Obtener estadísticas de uso de una promoción específica')
                    ->withNumberParameter('promotion_id', 'ID de la promoción')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (int $promotion_id, string $start_date, string $end_date) use ($branchId) {
                        $result = app(PromotionReportService::class)->getUsageStats(
                            $promotion_id,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                            $branchId,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ QUOTES & INVOICES ════════════════
            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_status_summary')
                    ->for('Obtener resumen de cotizaciones agrupadas por estado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getQuoteStatusSummary(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_conversion_rate')
                    ->for('Obtener la tasa de conversión de cotizaciones a ventas en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getConversionRate(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'invoices.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('invoice_status_summary')
                    ->for('Obtener resumen de facturas (CFDI) agrupadas por estado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getInvoiceStatusSummary(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'invoices.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('invoice_aging')
                    ->for('Listar facturas (CFDI) pendientes de cobro, agrupadas por antigüedad (0-30, 31-60, 61-90, 90+ días)')
                    ->withStringParameter('as_of_date', 'Fecha de referencia en formato YYYY-MM-DD (por defecto hoy)')
                    ->using(function (?string $as_of_date = null) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getInvoiceAging(
                            $branchId,
                            $as_of_date,
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ EXPENSES ════════════════
            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expenses_by_category')
                    ->for('Obtener gastos agrupados por categoría en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ExpenseReportService::class)->byCategory(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('expense_trend')
                    ->for('Obtener la tendencia mensual de gastos de los últimos N meses')
                    ->withNumberParameter('months', 'Cantidad de meses (por defecto 6)')
                    ->using(function (?int $months = 6) use ($branchId) {
                        $result = app(ExpenseReportService::class)->trend($branchId, $months ?? 6);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ SERVICE ORDERS ════════════════
            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_status_summary')
                    ->for('Obtener el resumen de órdenes de servicio agrupadas por estado')
                    ->using(function () use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getStatusSummary($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_workload')
                    ->for('Obtener la carga de trabajo por técnico en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getWorkloadByTechnician(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_turnaround')
                    ->for('Obtener el tiempo promedio de atención de órdenes de servicio en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getAverageTurnaroundTime(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ STAFF PERFORMANCE ════════════════
            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('sales_by_employee')
                    ->for('Obtener las ventas agrupadas por empleado en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(StaffPerformanceService::class)->salesByEmployee(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'financial_reports.access',
                'category'   => 'staff performance',
                'tool'       => (new Tool)->as('ranking_by_branch')
                    ->for('Obtener el ranking de sucursales por ventas en un período (solo para suscripciones multi-sucursal)')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($subscriptionId) {
                        $result = app(StaffPerformanceService::class)->rankingByBranch(
                            $subscriptionId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ DASHBOARD ════════════════
            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'daily sales dashboard',
                'tool'       => (new Tool)->as('today_sales_summary')
                    ->for('Obtener los KPIs de ventas del día actual')
                    ->using(function () use ($branchId) {
                        $result = app(SalesDashboardService::class)->getTodaySales($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'dashboard.see_sales',
                'category'   => 'weekly sales dashboard',
                'tool'       => (new Tool)->as('weekly_sales_trend')
                    ->for('Obtener la tendencia de ventas de los últimos 7 días')
                    ->using(function () use ($branchId) {
                        $result = app(SalesDashboardService::class)->getWeeklyTrend($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'financial_reports.access',
                'category'   => 'financial reports',
                'tool'       => (new Tool)->as('monthly_revenue_trend')
                    ->for('Obtener la tendencia de ingresos mensuales de los últimos N meses, con tasa de crecimiento mes contra mes')
                    ->withNumberParameter('months', 'Cantidad de meses (por defecto 6, máximo 24)')
                    ->using(function (?int $months = 6) use ($branchId) {
                        $result = app(FinancialReportService::class)->monthlyRevenueTrend(
                            $branchId,
                            min($months ?? 6, 24),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ NAVIGATION ════════════════
            [
                'permission' => null, // gated internally per-page by searchFor(), not by a single blanket permission
                'category'   => 'navigation',
                'tool'       => (new Tool)->as('find_page_location')
                    ->for('Find where in the system to do something or see certain information — e.g. "where do I register an expense", "where can I see cash register history". Returns page names with clickable links. Use this whenever the user asks "dónde", "cómo llego a", or similar navigation questions.')
                    ->withStringParameter('query', 'What the user wants to find or do, in their own words')
                    ->using(function (string $query) use ($user) {
                        $results = app(SiteNavigationRegistry::class)->searchFor($user, $query);

                        if (empty($results)) {
                            return json_encode(['message' => 'No encontré una página específica para eso. ¿Podrías darme más detalles sobre lo que buscas?']);
                        }

                        return json_encode($results, JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ EXPORT ════════════════
            [
                'permission' => ['products.access', 'products.import_export'],
                'category'   => 'downloadable Excel exports',
                'tool'       => (new Tool)->as('export_products_excel')
                    ->for('Generar un archivo Excel descargable con el catálogo completo de productos')
                    ->using(function () use ($subscriptionId) {
                        $filename = 'exports/' . $subscriptionId . '/productos_' . now()->timestamp . '.xlsx';

                        Excel::store(new \App\Exports\ProductsExport, $filename, config('ai-agent.export_disk', 'local'));

                        $url = URL::temporarySignedRoute(
                            'ai-agent.download',
                            now()->addMinutes(config('ai-agent.download_url_ttl', 15)),
                            ['path' => rtrim(strtr(base64_encode($filename), '+/', '-_'), '=')],
                        );

                        return json_encode([
                            'download_url'       => $url,
                            'expires_in_minutes' => config('ai-agent.download_url_ttl', 15),
                        ]);
                    }),
            ],

            // ════════════════ CRUD: CUSTOMERS ════════════════
            [
                'permission' => 'customers.create',
                'category'   => 'customers (crear/editar)',
                'tool'       => (new Tool)->as('create_customer')
                    ->for('Crear un nuevo cliente. REQUIERE modo escritura activado.')
                    ->withStringParameter('name', 'Nombre completo del cliente')
                    ->withStringParameter('email', 'Email del cliente (opcional)')
                    ->withStringParameter('phone', 'Teléfono del cliente (opcional)')
                    ->withStringParameter('notes', 'Notas adicionales (opcional)')
                    ->using(function (string $name, ?string $email = null, ?string $phone = null, ?string $notes = null) use ($branchId) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $customer = Customer::create([
                            'branch_id' => $branchId,
                            'name'      => $name,
                            'email'     => $email,
                            'phone'     => $phone,
                            'notes'     => $notes,
                            'balance'   => 0,
                        ]);

                        return json_encode([
                            'message' => 'Cliente creado exitosamente.',
                            'customer' => [
                                'id'    => $customer->id,
                                'name'  => $customer->name,
                                'email' => $customer->email,
                                'phone' => $customer->phone,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'customers.edit',
                'category'   => 'customers (crear/editar)',
                'tool'       => (new Tool)->as('update_customer')
                    ->for('Actualizar los datos de un cliente existente. REQUIERE modo escritura activado.')
                    ->withNumberParameter('customer_id', 'ID del cliente a actualizar')
                    ->withStringParameter('name', 'Nuevo nombre (opcional)')
                    ->withStringParameter('email', 'Nuevo email (opcional)')
                    ->withStringParameter('phone', 'Nuevo teléfono (opcional)')
                    ->withStringParameter('notes', 'Nuevas notas (opcional)')
                    ->using(function (int $customer_id, ?string $name = null, ?string $email = null, ?string $phone = null, ?string $notes = null) use ($branchId) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $data = array_filter([
                            'name'  => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'notes' => $notes,
                        ], fn ($v) => $v !== null);

                        if (empty($data)) {
                            return json_encode(['error' => 'Debes proporcionar al menos un campo para actualizar.']);
                        }

                        $customer = Customer::where('branch_id', $branchId)->findOrFail($customer_id);
                        $customer->update($data);

                        return json_encode([
                            'message' => 'Cliente actualizado exitosamente.',
                            'customer' => [
                                'id'    => $customer->id,
                                'name'  => $customer->name,
                                'email' => $customer->email,
                                'phone' => $customer->phone,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ LOOKUP: EXPENSE CATEGORIES ════════════════
            [
                'permission' => 'expenses.access',
                'category'   => 'expenses',
                'tool'       => (new Tool)->as('search_expense_categories')
                    ->for('Buscar categorías de gasto por nombre. Úsala SIEMPRE antes de registrar un gasto para obtener el ID correcto de la categoría.')
                    ->withStringParameter('query', 'Nombre parcial de la categoría (dejar vacío para listar todas)')
                    ->using(function (?string $query = null) use ($branchId) {
                        $categories = ExpenseCategory::query()
                            ->where('branch_id', $branchId)
                            ->when($query, fn ($q) => $q->where('name', 'LIKE', "%{$query}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get(['id', 'name']);

                        if ($categories->isEmpty()) {
                            return json_encode([
                                'message' => $query
                                    ? "No se encontró ninguna categoría de gasto que coincida con '{$query}'. Pregunta al usuario si desea crear una nueva categoría con ese nombre."
                                    : 'No hay categorías de gasto registradas. Pregunta al usuario si desea crear una.',
                                'categories' => [],
                            ], JSON_PRETTY_PRINT);
                        }

                        return json_encode([
                            'message' => 'Categorías encontradas.',
                            'categories' => $categories->toArray(),
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CRUD: PRODUCTS ════════════════
            [
                'permission' => 'products.edit',
                'category'   => 'products (crear/editar)',
                'tool'       => (new Tool)->as('update_product_price')
                    ->for('Actualizar el precio de venta de un producto. REQUIERE modo escritura activado.')
                    ->withNumberParameter('product_id', 'ID del producto')
                    ->withNumberParameter('selling_price', 'Nuevo precio de venta')
                    ->using(function (int $product_id, float $selling_price) use ($branchId) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $product = Product::where('branch_id', $branchId)->findOrFail($product_id);
                        $oldPrice = $product->selling_price;
                        $product->update(['selling_price' => $selling_price]);

                        return json_encode([
                            'message' => 'Precio actualizado exitosamente.',
                            'product' => [
                                'id'            => $product->id,
                                'name'          => $product->name,
                                'previous_price' => $oldPrice,
                                'new_price'     => $product->selling_price,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'products.edit',
                'category'   => 'products (crear/editar)',
                'tool'       => (new Tool)->as('update_product_stock')
                    ->for('Actualizar el stock de un producto. REQUIERE modo escritura activado.')
                    ->withNumberParameter('product_id', 'ID del producto')
                    ->withNumberParameter('stock', 'Nueva cantidad de stock')
                    ->using(function (int $product_id, int $stock) use ($branchId) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $product = Product::where('branch_id', $branchId)->findOrFail($product_id);
                        $oldStock = $product->stock;
                        $product->update(['stock' => $stock]);

                        return json_encode([
                            'message' => 'Stock actualizado exitosamente.',
                            'product' => [
                                'id'           => $product->id,
                                'name'         => $product->name,
                                'previous_stock' => $oldStock,
                                'new_stock'    => $product->stock,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CRUD: EXPENSES ════════════════
            [
                'permission' => 'expenses.create',
                'category'   => 'expenses (crear)',
                'tool'       => (new Tool)->as('create_expense')
                    ->for('Registrar un nuevo gasto. REQUIERE modo escritura activado.')
                    ->withStringParameter('description', 'Descripción del gasto')
                    ->withNumberParameter('amount', 'Monto del gasto')
                    ->withNumberParameter('expense_category_id', 'ID de la categoría de gasto')
                    ->withStringParameter('payment_method', 'Método de pago (efectivo, tarjeta, transferencia)')
                    ->withStringParameter('expense_date', 'Fecha del gasto en formato YYYY-MM-DD (por defecto hoy)')
                    ->using(function (string $description, float $amount, int $expense_category_id, string $payment_method = 'efectivo', ?string $expense_date = null) use ($branchId, $user) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        $expense = app(StoreExpenseAction::class)->execute([
                            'branch_id'           => $branchId,
                            'description'         => $description,
                            'amount'              => $amount,
                            'expense_category_id' => $expense_category_id,
                            'payment_method'      => $payment_method,
                            'expense_date'        => $expense_date ?? now()->toDateString(),
                        ], $user);

                        return json_encode([
                            'message' => 'Gasto registrado exitosamente.',
                            'expense' => [
                                'id'          => $expense->id,
                                'description' => $expense->description,
                                'amount'      => $expense->amount,
                                'date'        => $expense->expense_date->toDateString(),
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            // ════════════════ CRUD: DELETE ════════════════
            [
                'permission' => 'products.delete',
                'category'   => 'products (eliminar)',
                'tool'       => (new Tool)->as('delete_product')
                    ->for('Eliminar un producto. ¡OPERACIÓN DESTRUCTIVA! REQUIERE modo escritura activado y confirmación explícita del usuario.')
                    ->withNumberParameter('product_id', 'ID del producto a eliminar')
                    ->withStringParameter('confirmation', 'Debe ser exactamente "CONFIRMAR" para proceder')
                    ->using(function (int $product_id, string $confirmation) use ($branchId) {
                        $gate = app(WriteModeGate::class);
                        if (! $gate->isEnabled()) {
                            return json_encode(['error' => $gate->rejectionMessage()]);
                        }

                        if ($confirmation !== 'CONFIRMAR') {
                            return json_encode(['error' => 'Para eliminar un producto, debes pasar confirmation="CONFIRMAR". Esta operación no se puede deshacer.']);
                        }

                        $product = Product::where('branch_id', $branchId)->findOrFail($product_id);
                        $productName = $product->name;
                        $product->delete();

                        return json_encode([
                            'message' => 'Producto eliminado exitosamente.',
                            'product' => [
                                'id'   => $product_id,
                                'name' => $productName,
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}
