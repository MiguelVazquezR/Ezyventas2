<?php

namespace App\AiTools;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\FinancialReportService;
use App\Services\InventoryReportService;
use Carbon\Carbon;
use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Ezyventas\AiAgent\Schema\Tool;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Maatwebsite\Excel\Facades\Excel;

class EzyVentasToolProvider implements AiToolProvider
{
    public function tools(Authenticatable $user): array
    {
        $branchId = $user->branch_id;
        $subscriptionId = $user->branch->subscription_id;

        return [
            // ──── REPORTS ────
            Tool::as('financial_report')
                ->for('Obtener KPIs financieros, ventas por canal, gastos por categoría y resumen de bancos para un rango de fechas determinado')
                ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                ->using(function (string $start_date, string $end_date) use ($branchId) {
                    $service = new FinancialReportService(
                        $branchId,
                        Carbon::parse($start_date),
                        Carbon::parse($end_date),
                    );

                    $data = $service->generateReportData();

                    // Keep payload compact — remove heavy chart arrays
                    unset($data['chartData']);

                    return json_encode($data, JSON_PRETTY_PRINT);
                }),

            Tool::as('inventory_dead_stock')
                ->for('Listar productos que NO han tenido ventas en los últimos N días (inventario muerto)')
                ->withNumberParameter('days', 'Días sin movimiento, ej. 30, 60, 90')
                ->withStringParameter('category_id', 'ID de categoría (opcional, usar null para todas)')
                ->using(function (int $days, ?string $category_id = null) use ($branchId) {
                    $categoryId = $category_id && $category_id !== 'null' ? (int) $category_id : null;

                    $result = app(InventoryReportService::class)->deadStock(
                        $branchId,
                        Carbon::now()->subDays($days),
                        Carbon::now(),
                        $categoryId,
                    );

                    return json_encode($result, JSON_PRETTY_PRINT);
                }),

            // ──── SALES ────
            Tool::as('recent_transactions')
                ->for('Obtener las transacciones más recientes de una sucursal')
                ->withNumberParameter('limit', 'Cantidad máxima de transacciones (máx 20)')
                ->using(function (int $limit = 10) use ($branchId) {
                    $limit = min($limit, 20);

                    $transactions = Transaction::query()
                        ->where('branch_id', $branchId)
                        ->with(['customer:id,name', 'user:id,name'])
                        ->latest()
                        ->take($limit)
                        ->get(['id', 'folio', 'customer_id', 'user_id', 'status', 'total', 'created_at']);

                    return json_encode($transactions, JSON_PRETTY_PRINT);
                }),

            // ──── CUSTOMERS ────
            Tool::as('search_customers')
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

            // ──── PRODUCTS ────
            Tool::as('search_products')
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

            // ──── EXPORT ────
            Tool::as('export_products_excel')
                ->for('Generar un archivo Excel descargable con el catálogo completo de productos')
                ->using(function () use ($subscriptionId) {
                    $filename = 'exports/' . $subscriptionId . '/productos_' . now()->timestamp . '.xlsx';

                    Excel::store(new \App\Exports\ProductsExport, $filename, config('ai-agent.export_disk', 'local'));

                    $url = URL::temporarySignedRoute(
                        'ai-agent.download',
                        now()->addMinutes(config('ai-agent.download_url_ttl', 15)),
                        ['path' => $filename],
                    );

                    return json_encode([
                        'download_url'       => $url,
                        'expires_in_minutes' => config('ai-agent.download_url_ttl', 15),
                    ]);
                }),
        ];
    }
}
