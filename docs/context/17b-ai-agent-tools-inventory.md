# 17b — AI Agent Tools Inventory

> Generated: 2026-07-19 | Source: `app/AiTools/EzyVentasToolProvider.php` and referenced Services
> 
> Complete inventory of all tools registered with the AI agent via Prism PHP, including the underlying service calls, database models touched, tenant scoping, and read/write classification.

---

## Architecture Summary

- **Tool definitions**: `app/AiTools/EzyVentasToolProvider.php` (implements `Ezyventas\AiAgent\Contracts\AiToolProvider`)
- **Tool registration binding**: `app/Providers/AppServiceProvider.php` line 18 — `$this->app->bind(AiToolProvider::class, EzyVentasToolProvider::class)`
- **Tool resolution**: `packages/ai-agent/src/Support/ToolRegistry.php` — calls `$provider->tools($user)` and filters by Spatie permissions
- **LLM integration**: `packages/ai-agent/src/Support/AiAgentManager.php` — uses Prism PHP `->withTools($tools)` with max 6 tool steps per message
- **System prompt**: `AiAgentManager::systemPrompt()` lines ~213–228 — dynamically built with business name, branch name, current date, and permitted categories
- **Routes**: `packages/ai-agent/routes/ai-agent.php` — 3 endpoints: `POST conversations`, `POST conversations/{conversation}/messages`, `GET usage`; plus signed download route
- **All tools are READ-ONLY** — no tool performs INSERT, UPDATE, or DELETE. The only write operations are framework-level audit: `AiToolExecution` rows (tool call log) and `AiUsageMonthly` increments (token/cost tracking).

---

## Tool Inventory (33 tools across 14 categories)

### 1. Financial Reports

#### `financial_report`
- **Description**: Obtener KPIs financieros, ventas por canal, gastos por categoría, distribución de ventas por hora (cuando el rango es un solo día), gráfica de tendencia y resumen de bancos para un rango de fechas determinado.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `App\Services\FinancialReportService::generateReportData()` (line ~75 of `EzyVentasToolProvider.php`)
- **Models/tables touched**: `transactions`, `payments`, `expenses`, `bank_accounts`, `expense_categories` (all scoped by `branch_id` via constructor)
- **Tenant scoping**: `branch_id` (derived from `$user->branch_id` server-side, not from LLM parameters)
- **Read/Write**: Read-only
- **Permission**: `financial_reports.access`

---

### 2. Inventory

#### `inventory_dead_stock`
- **Description**: Listar productos que NO han tenido ventas en los últimos N días (inventario muerto).
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `days` | number | yes | e.g., 30, 60, 90 |
  | `category_id` | string | no | ID de categoría (use "null" for all) |
- **Service called**: `InventoryReportService::deadStock()` (line ~89)
- **Models/tables touched**: `branch_product`, `products`, `transaction_items`, `transactions`, `product_attributes`, `categories`, `brands`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `products.access`

#### `low_stock_products`
- **Description**: Listar productos con stock bajo o por debajo del mínimo.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `threshold` | number | no | Default 5 |
- **Service called**: `SalesDashboardService::getLowStockProducts()` (line ~249)
- **Models/tables touched**: `branch_product`, `products`, `product_attributes` (via subquery)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `dashboard.see_inventory_details`

---

### 3. Transactions / Sales

#### `recent_transactions`
- **Description**: Obtener las transacciones más recientes de una sucursal.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `limit` | number | no | Default 10, max 20 |
- **Service called**: `TransactionQueryService::search()` (line ~111)
- **Models/tables touched**: `transactions` (with `customer`, `user` relations)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `transactions.access`

#### `search_transactions`
- **Description**: Buscar transacciones con filtros: estado, método de pago, canal y rango de fechas.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `status` | string | no | e.g., completado, pendiente, cancelado; or "null" |
  | `payment_method` | string | no | efectivo, tarjeta, transferencia; or "null" |
  | `date_from` | string (YYYY-MM-DD) | no | or "null" |
  | `date_to` | string (YYYY-MM-DD) | no | or "null" |
  | `channel` | string | no | tienda, en_linea; or "null" |
- **Service called**: `TransactionQueryService::search()` (line ~122)
- **Models/tables touched**: `transactions` (joins `payments` for payment_method filter, with `customer`, `user` relations)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `transactions.access`

---

### 4. Customers

#### `search_customers`
- **Description**: Buscar clientes por nombre, email o teléfono.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `query` | string | yes | Partial name, email, or phone |
- **Service called**: Direct Eloquent query on `Customer` model (line ~140)
- **Models/tables touched**: `customers`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `customers.access`

#### `customer_purchase_history`
- **Description**: Obtener el historial de compras recientes de un cliente específico.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `customer_query` | string | yes | Name, email, or phone to look up the customer |
- **Service called**: `CustomerReportService::getPurchaseHistory()` (line ~165)
- **Models/tables touched**: `customers`, `transactions` (scoped by customer ID)
- **Tenant scoping**: `branch_id` (customer lookup scoped; transactions also branch-scoped via service)
- **Read/Write**: Read-only
- **Permission**: `customers.see_financial_info`

#### `customer_account_statement`
- **Description**: Obtener el estado de cuenta y movimientos de saldo de un cliente.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `customer_query` | string | yes | Name, email, or phone to look up the customer |
- **Service called**: `CustomerReportService::getAccountStatement()` (line ~180)
- **Models/tables touched**: `customers`, `customer_balance_movements`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `customers.see_financial_info`

#### `top_customers`
- **Description**: Obtener el ranking de los clientes con mayor gasto en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
  | `limit` | number | no | Default 10 |
- **Service called**: `CustomerReportService::getTopCustomers()` (line ~197)
- **Models/tables touched**: `customers`, `transactions` (aggregated)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `customers.see_financial_info`

#### `customer_recency`
- **Description**: Listar clientes que no han comprado en los últimos N días, o que compraron recientemente, para identificar clientes inactivos.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `days` | number | yes | e.g., 30, 60, 90 |
  | `direction` | string | no | "inactive" (last purchase > N days ago or never) or "recent" (last purchase within N days), default "inactive" |
  | `limit` | number | no | Default 20, max 50 |
- **Service called**: `CustomerReportService::getCustomerRecency()` (method added 2026-07-19)
- **Models/tables touched**: `customers`, `transactions` (via `withMax('transactions', 'created_at')`)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `customers.see_financial_info`

---

### 5. Products

#### `search_products`
- **Description**: Buscar productos por nombre o SKU.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `query` | string | yes | Partial name or SKU |
- **Service called**: Direct Eloquent query on `Product` model (line ~224)
- **Models/tables touched**: `products` (with `category` relation)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `products.access`

#### `sales_by_product`
- **Description**: Obtener las ventas agrupadas por producto o categoría en un período, ordenadas por monto o cantidad vendida.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
  | `group_by` | string | no | "product" or "category", default "product" |
  | `limit` | number | no | Default 10, max 50 |
- **Service called**: `InventoryReportService::salesByProduct()` (method added 2026-07-19)
- **Models/tables touched**: `transaction_items`, `transactions`, `products`, `product_attributes`, `categories` (only Product/ProductAttribute itemable types)
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `products.access` (chosen to match existing product query tools — sales data is revenue-aggregated, not individual transactions)

#### `product_margin_report`
- **Description**: Obtener el margen de ganancia por producto en un período, calculado como (precio de venta - costo) × cantidad vendida.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
  | `limit` | number | no | Default 10, max 50 |
  | `sort` | string | no | "margin_amount" or "margin_percent", default "margin_amount" |
- **Service called**: `InventoryReportService::productMarginReport()` (method added 2026-07-19)
- **Models/tables touched**: `transaction_items`, `transactions`, `products`, `product_attributes`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `financial_reports.access` (cost_price exposes supplier cost — restricted to users with financial permissions)
- **Cost handling**: Products with `cost_price = null` are flagged as `cost_not_set: true` and excluded from margin calculations

---

### 6. Cash Register

#### `cash_register_session_summary`
- **Description**: Obtener el resumen de una sesión de caja: totales por método de pago y conciliación bancaria.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `session_id` | number | yes | ID de la sesión de caja registradora |
- **Service called**: `CashRegisterReportService::getSessionSummary()` (line ~263)
- **Models/tables touched**: `cash_register_sessions` (with `cashRegister` relation for branch scoping), `session_cash_movements`
- **Tenant scoping**: `branch_id` (via `cashRegister.branch_id` check)
- **Read/Write**: Read-only
- **Permission**: `cash_registers.sessions.access`

#### `cash_register_discrepancies`
- **Description**: Listar sesiones de caja con discrepancias entre el efectivo contado y el esperado.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `CashRegisterReportService::getDiscrepancies()` (line ~277)
- **Models/tables touched**: `cash_register_sessions`, `session_cash_movements`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `cash_registers.sessions.access`

#### `daily_cash_close`
- **Description**: Obtener el cierre de caja de una fecha específica.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `CashRegisterReportService::getDailyClose()` (line ~289)
- **Models/tables touched**: `cash_register_sessions`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `cash_registers.sessions.access`

---

### 7. Promotions

#### `active_promotions`
- **Description**: Listar las promociones actualmente activas.
- **Parameters**: None
- **Service called**: `PromotionReportService::getActivePromotions()` (line ~304)
- **Models/tables touched**: `promotions` (with `rules`, `effects`), `branches` (to resolve `subscription_id` from `branch_id`)
- **Tenant scoping**: `subscription_id` (resolved from `branch_id` via DB query, line ~18 of `PromotionReportService`)
- **Read/Write**: Read-only
- **Permission**: `products.manage_promos`

#### `promotion_usage_stats`
- **Description**: Obtener estadísticas de uso de una promoción específica.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `promotion_id` | number | yes | |
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `PromotionReportService::getUsageStats()` (line ~317)
- **Models/tables touched**: `promotions`, `promotion_transaction` (pivot), `transactions`
- **Tenant scoping**: `subscription_id` (resolved from `branch_id` via DB query, then scopes `Promotion::where('subscription_id', ...)` before `findOrFail` — cross-subscription check added 2026-07-19)
- **Read/Write**: Read-only
- **Permission**: `products.manage_promos`

---

### 8. Quotes & Invoices

#### `quote_status_summary`
- **Description**: Obtener resumen de cotizaciones agrupadas por estado.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `QuoteInvoiceReportService::getQuoteStatusSummary()` (line ~339)
- **Models/tables touched**: `quotes`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `quotes.access`

#### `quote_conversion_rate`
- **Description**: Obtener la tasa de conversión de cotizaciones a ventas en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `QuoteInvoiceReportService::getConversionRate()` (line ~352)
- **Models/tables touched**: `quotes`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `quotes.access`

#### `invoice_status_summary`
- **Description**: Obtener resumen de facturas (CFDI) agrupadas por estado.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `QuoteInvoiceReportService::getInvoiceStatusSummary()` (line ~365)
- **Models/tables touched**: `invoices`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `invoices.access`

#### `invoice_aging`
- **Description**: Listar facturas (CFDI) pendientes de cobro, agrupadas por antigüedad (0-30, 31-60, 61-90, 90+ días).
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `as_of_date` | string (YYYY-MM-DD) | no | Default = today |
- **Service called**: `QuoteInvoiceReportService::getInvoiceAging()` (method added 2026-07-19)
- **Models/tables touched**: `invoices`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `invoices.access`
- **Important note**: The `invoices` table has no explicit "paid" flag — payment tracking lives in the POS system (`transactions`/`payments`), not on the invoice record. This tool buckets `certificada` invoices as the closest proxy for "outstanding collection." If a dedicated payment-status column is added to invoices in the future, this method should be updated.

---

### 9. Expenses

#### `expenses_by_category`
- **Description**: Obtener gastos agrupados por categoría en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `ExpenseReportService::byCategory()` (line ~381)
- **Models/tables touched**: `expenses`, `expense_categories`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `expenses.access`

#### `expense_trend`
- **Description**: Obtener la tendencia mensual de gastos de los últimos N meses.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `months` | number | no | Default 6 |
- **Service called**: `ExpenseReportService::trend()` (line ~392)
- **Models/tables touched**: `expenses`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `expenses.access`

---

### 10. Service Orders

#### `service_order_status_summary`
- **Description**: Obtener el resumen de órdenes de servicio agrupadas por estado.
- **Parameters**: None
- **Service called**: `ServiceOrderReportService::getStatusSummary()` (line ~409)
- **Models/tables touched**: `service_orders`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `services.orders.access`

#### `service_order_workload`
- **Description**: Obtener la carga de trabajo por técnico en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `ServiceOrderReportService::getWorkloadByTechnician()` (line ~422)
- **Models/tables touched**: `service_orders`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `services.orders.access`

#### `service_order_turnaround`
- **Description**: Obtener el tiempo promedio de atención de órdenes de servicio en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `ServiceOrderReportService::getAverageTurnaroundTime()` (line ~434)
- **Models/tables touched**: `service_orders`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `services.orders.access`

---

### 11. Staff Performance

#### `sales_by_employee`
- **Description**: Obtener las ventas agrupadas por empleado en un período.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `StaffPerformanceService::salesByEmployee()` (line ~451)
- **Models/tables touched**: `transactions`, `users`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `financial_reports.access`

#### `ranking_by_branch`
- **Description**: Obtener el ranking de sucursales por ventas en un período (solo para suscripciones multi-sucursal).
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `start_date` | string (YYYY-MM-DD) | yes | |
  | `end_date` | string (YYYY-MM-DD) | yes | |
- **Service called**: `StaffPerformanceService::rankingByBranch()` (line ~464)
- **Models/tables touched**: `transactions`, `branches`
- **Tenant scoping**: `subscription_id` (not `branch_id` — intentionally cross-branch within the same subscription)
- **Read/Write**: Read-only
- **Permission**: `financial_reports.access`

---

### 12. Dashboard

#### `today_sales_summary`
- **Description**: Obtener los KPIs de ventas del día actual.
- **Parameters**: None
- **Service called**: `SalesDashboardService::getTodaySales()` (line ~483)
- **Models/tables touched**: `transactions`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `dashboard.see_sales`

#### `weekly_sales_trend`
- **Description**: Obtener la tendencia de ventas de los últimos 7 días.
- **Parameters**: None
- **Service called**: `SalesDashboardService::getWeeklyTrend()` (line ~495)
- **Models/tables touched**: `transactions`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `dashboard.see_sales`

#### `monthly_revenue_trend`
- **Description**: Obtener la tendencia de ingresos mensuales de los últimos N meses, con tasa de crecimiento mes contra mes.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `months` | number | no | Default 6, max 24 |
- **Service called**: `FinancialReportService::monthlyRevenueTrend()` (method added 2026-07-19)
- **Models/tables touched**: `transactions`
- **Tenant scoping**: `branch_id`
- **Read/Write**: Read-only
- **Permission**: `financial_reports.access`

---

### 13. Navigation

#### `find_page_location`
- **Description**: Find where in the system to do something or see certain information — returns page names with clickable links.
- **Parameters**:
  | Name | Type | Required | Notes |
  |---|---|---|---|
  | `query` | string | yes | What the user wants to find ("dónde registro un gasto", etc.) |
- **Service called**: `App\AiTools\SiteNavigationRegistry::searchFor()` (line ~515)
- **Models/tables touched**: None — operates on a hardcoded array of 22 pages with keyword matching, filtered by user permissions
- **Tenant scoping**: N/A — navigation is the same for all tenants; only permission-filtered per user
- **Read/Write**: Read-only
- **Permission**: `null` (gated internally per-page by the registry's `searchFor()` method, not by a single blanket permission)

---

### 14. Export

#### `export_products_excel`
- **Description**: Generar un archivo Excel descargable con el catálogo completo de productos.
- **Parameters**: None
- **Service called**: `Maatwebsite\Excel\Facades\Excel::store()` using `App\Exports\ProductsExport` (line ~532)
- **Models/tables touched**: `products` (via the export class). Write: stores file to disk (`exports/{subscriptionId}/productos_{timestamp}.xlsx`). Returns a signed temporary download URL.
- **Tenant scoping**: `subscription_id` (export path includes subscription ID; the `ProductsExport` class is expected to scope by subscription)
- **Read/Write**: **Read-Write** (writes a file to the storage disk and returns a signed URL — this is the only tool with a side effect beyond audit logging)
- **Permission**: `products.access` AND `products.import_export` (both required)

---

## Scoping Summary

| Scoping Method | Tools | Notes |
|---|---|---|
| `branch_id` (from `$user->branch_id`) | 23 tools | All report, search, and summary tools |
| `subscription_id` (from `$user->branch->subscription_id`) | 3 tools: `ranking_by_branch`, `active_promotions`, `export_products_excel` | Cross-branch within same subscription |
| No data scoping (hardcoded array, permission-filtered) | 1 tool: `find_page_location` | Navigation registry |

---

## Gaps / Not Yet Implemented

The following business questions have **no corresponding tool**. The AI agent cannot answer these today:

### Sales & Revenue
1. ~~**Sales aggregation by product/category over time**~~ — ✅ Covered by `sales_by_product` (2026-07-19)
2. ~~**Margin/profitability per product or transaction**~~ — ✅ Covered by `product_margin_report` (2026-07-19)
3. **Sales by hour / time-of-day patterns** — ✅ Covered by `financial_report` (now includes `hourlySales` for single-day ranges; 2026-07-19)

### Customers
4. ~~**Customer recency / RFM segmentation**~~ — ✅ Partially covered by `customer_recency` (2026-07-19). RFM scoring (frequency/monetary) not yet implemented.
5. **Customer acquisition over time** — No tool to count new customers per month or track growth.

### Service Orders
6. **Technician performance metrics** — `service_order_workload` gives counts per technician, but no tool exposes completion rate, average turnaround per technician, or revenue per technician.
7. **Parts/labor breakdown** — No tool separates product-based items from service-based items within service orders.

### Cash Register
8. **Cash register reconciliation audit** — `cash_register_discrepancies` lists sessions with differences, but no tool checks whether any unreconciled sessions exist right now or computes aggregate cash leakage over time.
9. **Multi-session comparisons** — No tool compares metrics across sessions (e.g., average cash difference per session, trends in discrepancies).

### Inventory
10. **Inventory reorder alerts** — `low_stock_products` uses `min_stock` thresholds, but no tool proactively alerts "these products will run out in N days based on recent sales velocity."
11. **Inventory valuation trends** — No tool shows total inventory value over time (just `dead_stock` which is point-in-time).

### Promotions
12. **Promotion effectiveness / ROI** — `promotion_usage_stats` returns times-applied and total discount, but no tool ties promotions to incremental revenue (e.g., "did transactions with this promotion have higher average baskets?").
13. **Promotion overlap detection** — No tool identifies overlapping active promotions that may conflict.

### Cross-Branch
14. **Cross-branch comparisons** (by the same metric) — `ranking_by_branch` only ranks by sales. No tool compares branches by expenses, margins, customer count, or service order volume side-by-side.

### Time-Series Trends
15. ~~**Time-series trend for revenue**~~ — ✅ Covered by `monthly_revenue_trend` (2026-07-19). Provides monthly revenue with month-over-month growth rates.
16. **Forecasting / projections** — No predictive tools or simple linear projections.

### Invoices
17. ~~**Invoice aging / pending collection**~~ — ✅ Covered by `invoice_aging` (2026-07-19). Note: buckets `certificada` invoices only; no explicit "paid" flag exists on the invoices table.
18. **Invoice revenue by customer** — No tool aggregates invoice totals per customer.

### Online Store
19. **Online store metrics** — No tool for online order volume, online vs. in-store split, or abandoned orders.

### General
20. **No tool can perform WRITE actions on business data** — The agent cannot create a customer, product, transaction, expense, or any other business record. All business tools are read-only. The only side-effect tool is `export_products_excel` (writes a file).

---

## Key Observations

1. **All business tools are read-only** — The only write operations are the framework-level audit trail (`AiToolExecution`, `AiUsageMonthly` increments) managed by `AiAgentManager`, not by tools themselves. The `export_products_excel` tool writes a file to disk but does not modify database records.

2. **Tools call App Services, not raw Eloquent in most cases** — 27 of 33 tools delegate to dedicated Service classes. Only 2 tools (`search_customers`, `search_products`) use direct Eloquent queries from within the tool closure. This follows the project's architecture guidelines.

3. **Permission gating is consistent** — Every tool definition includes a `permission` key (nullable for `find_page_location` which gates internally). Tools are filtered by `$user->can()` before being passed to the LLM.

4. **Tenant scoping is server-side only** — The `AiToolProvider` contract explicitly states: "Each tool closure MUST derive tenant scoping (subscription_id / branch_id) from $user server-side — never from a tool parameter supplied by the LLM." All tools comply. The `promotion_usage_stats` cross-subscription leak was fixed on 2026-07-19.

5. **No streaming** — The chat is fully synchronous (`POST conversations/{id}/messages` returns the complete response). The route file mentions "Phase 11 (future): token-by-token streaming via queued job + Pusher."

6. **Credit/token limiting is coarse** — `AiAgentManager::ask()` checks `$subscription->getAiCreditLimitData()` before calling the LLM. Only monthly totals are tracked; no real-time deduction during tool execution. If a user hits the limit, they get a special `limit_exceeded` message with no tool calls.

7. **Hourly sales data is now exposed** — `financial_report` now includes `hourlySales` (a compact `{hour, total_sales, transaction_count}` array) for single-day date ranges, so the LLM can answer "what time of day do we sell the most?" questions. The raw `chartData` (labels + multi-series) is also included in the tool response.

8. **Invoice aging caveat** — The `invoice_aging` tool buckets `certificada` invoices by days since `issued_at`. There is no explicit "paid" flag on the invoices table; payment tracking lives in the POS system. This is noted in the tool description so future maintainers are aware.
