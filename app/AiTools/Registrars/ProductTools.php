<?php

namespace App\AiTools\Registrars;

use App\Models\Product;
use App\Services\InventoryReportService;
use App\Services\SalesDashboardService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ProductTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
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

            // ════════════════ CRUD ════════════════
            [
                'permission' => 'products.edit',
                'category'   => 'products (crear/editar)',
                'tool'       => (new Tool)->as('update_product_price')
                    ->for('Actualizar el precio de venta de un producto. REQUIERE modo escritura activado.')
                    ->withNumberParameter('product_id', 'ID del producto')
                    ->withNumberParameter('selling_price', 'Nuevo precio de venta')
                    ->using(function (int $product_id, float $selling_price) use ($branchId) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
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
                        $gate = app(\App\AiTools\WriteModeGate::class);
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

            [
                'permission' => 'products.delete',
                'category'   => 'products (eliminar)',
                'tool'       => (new Tool)->as('delete_product')
                    ->for('Eliminar un producto. ¡OPERACIÓN DESTRUCTIVA! REQUIERE modo escritura activado y confirmación explícita del usuario.')
                    ->withNumberParameter('product_id', 'ID del producto a eliminar')
                    ->withStringParameter('confirmation', 'Debe ser exactamente "CONFIRMAR" para proceder')
                    ->using(function (int $product_id, string $confirmation) use ($branchId) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
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