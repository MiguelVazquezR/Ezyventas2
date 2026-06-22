<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio para generar reportes de inventario.
 * Cada método retorna un array con los datos listos para el frontend.
 */
class InventoryReportService
{
    /**
     * 1. Mercancía sin movimiento (inventario muerto).
     * Productos con stock > 0 que NO tuvieron salidas en el período.
     */
    public function deadStock(int $branchId, Carbon $startDate, Carbon $endDate, ?int $categoryId = null, int $minStock = 1): array
    {
        // IDs de productos que SÍ tuvieron movimiento (ventas) en el período
        $movedProductIds = TransactionItem::where('itemable_type', Product::class)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->pluck('itemable_id')
            ->toArray();

        $movedVariantIds = TransactionItem::where('itemable_type', ProductAttribute::class)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->pluck('itemable_id')
            ->toArray();

        $allMovedIds = array_unique(array_merge($movedProductIds, $movedVariantIds));

        // Productos simples con stock > min que no están en la lista de movidos
        $query = Product::query()
            ->whereHas('branches', function ($q) use ($branchId, $minStock) {
                $q->where('branch_id', $branchId)
                  ->where('current_stock', '>=', $minStock);
            })
            ->whereNotIn('id', $movedProductIds)
            ->with(['category', 'brand'])
            ->withSum(['branches' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }], 'branch_product.current_stock');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get()->map(function ($product) use ($branchId) {
            $stock = (float) ($product->branches_sum_branch_productcurrent_stock ?? 0);
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'current_stock' => $stock,
                'cost_price' => (float) $product->cost_price,
                'total_value' => round($stock * (float) $product->cost_price, 2),
                'last_sale_date' => $this->getLastSaleDate($product->id, Product::class, $branchId),
            ];
        })->sortByDesc('total_value')->values();

        return [
            'title' => 'Mercancía sin movimiento',
            'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
            'headers' => ['Producto', 'SKU', 'Categoría', 'Marca', 'Stock actual', 'Costo unit.', 'Valor total', 'Última venta'],
            'rows' => $products,
            'summary' => [
                'total_products' => $products->count(),
                'total_value' => round($products->sum('total_value'), 2),
                'total_stock' => round($products->sum('current_stock'), 2),
            ],
        ];
    }

    /**
     * 2. Mercancía con mayor movimiento (top sellers).
     * Ranking por cantidad vendida o por número de transacciones.
     */
    public function topSellers(int $branchId, Carbon $startDate, Carbon $endDate, string $orderBy = 'quantity', ?int $categoryId = null, int $limit = 50): array
    {
        $items = TransactionItem::where(function ($q) {
                $q->where('itemable_type', Product::class)
                  ->orWhere('itemable_type', ProductAttribute::class);
            })
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->select('itemable_id', 'itemable_type',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT transaction_id) as transaction_count'),
                DB::raw('SUM(line_total) as total_revenue'))
            ->groupBy('itemable_id', 'itemable_type');

        if ($orderBy === 'quantity') {
            $items = $items->orderByDesc('total_quantity');
        } elseif ($orderBy === 'transactions') {
            $items = $items->orderByDesc('transaction_count');
        } else {
            $items = $items->orderByDesc('total_revenue');
        }

        $items = $items->limit($limit)->get();

        $results = $items->map(function ($item) use ($branchId) {
            $model = $item->itemable_type::find($item->itemable_id);
            if (!$model) return null;

            if ($model instanceof ProductAttribute) {
                $name = ($model->product?->name ?? 'Producto') . ' - ' . implode(' ', $model->attributes ?? []);
                $sku = $model->sku_suffix ?: $model->product?->sku;
                $costPrice = (float) ($model->product?->cost_price ?? 0);
            } else {
                $name = $model->name;
                $sku = $model->sku;
                $costPrice = (float) $model->cost_price;
            }

            return [
                'id' => $model->id,
                'name' => $name,
                'sku' => $sku,
                'total_quantity' => (float) $item->total_quantity,
                'transaction_count' => (int) $item->transaction_count,
                'total_revenue' => round((float) $item->total_revenue, 2),
                'total_cost' => round((float) $item->total_quantity * $costPrice, 2),
                'margin' => round((float) $item->total_revenue - ((float) $item->total_quantity * $costPrice), 2),
            ];
        })->filter()->values();

        return [
            'title' => 'Productos más vendidos',
            'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')} · Orden: " . match($orderBy) {
                'quantity' => 'por cantidad',
                'transactions' => 'por frecuencia',
                default => 'por ingreso',
            },
            'headers' => ['Producto', 'SKU', 'Cantidad vendida', 'Transacciones', 'Ingreso total', 'Costo total', 'Margen'],
            'rows' => $results,
            'summary' => [
                'total_quantity' => round($results->sum('total_quantity'), 2),
                'total_revenue' => round($results->sum('total_revenue'), 2),
                'total_margin' => round($results->sum('margin'), 2),
            ],
        ];
    }

    /**
     * 3. Rotación de inventario por producto.
     * rotación = ventas del período / stock promedio
     * stock_promedio = (stock_inicial + stock_final) / 2
     */
    public function inventoryTurnover(int $branchId, Carbon $startDate, Carbon $endDate, ?int $categoryId = null, int $limit = 50): array
    {
        // Ventas en el período
        $sales = TransactionItem::where('itemable_type', Product::class)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->select('itemable_id',
                DB::raw('SUM(quantity) as total_sold'))
            ->groupBy('itemable_id')
            ->get()
            ->keyBy('itemable_id');

        $productIds = $sales->keys();

        if ($productIds->isEmpty()) {
            return [
                'title' => 'Rotación de inventario',
                'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
                'headers' => ['Producto', 'SKU', 'Categoría', 'Ventas período', 'Stock actual', 'Rotación (veces)'],
                'rows' => collect(),
                'summary' => ['avg_turnover' => 0, 'high_rotation' => 0, 'low_rotation' => 0],
            ];
        }

        $query = Product::whereIn('id', $productIds)
            ->with(['category'])
            ->withSum(['branches' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }], 'branch_product.current_stock');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get()->map(function ($product) use ($sales) {
            $currentStock = (float) ($product->branches_sum_branch_productcurrent_stock ?? 0);
            $totalSold = (float) ($sales[$product->id]->total_sold ?? 0);

            // Stock promedio: asumimos que el stock final es el actual.
            // Stock inicial ≈ stock actual + ventas (aproximación simple)
            $avgStock = ($currentStock + $totalSold + $currentStock) / 2;
            $avgStock = max($avgStock, 0.01);

            $turnover = $totalSold / $avgStock;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'total_sold' => $totalSold,
                'current_stock' => $currentStock,
                'turnover' => round($turnover, 2),
            ];
        })->sortByDesc('turnover')->take($limit)->values();

        return [
            'title' => 'Rotación de inventario',
            'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
            'headers' => ['Producto', 'SKU', 'Categoría', 'Ventas período', 'Stock actual', 'Rotación (veces)'],
            'rows' => $products,
            'summary' => [
                'avg_turnover' => $products->isNotEmpty() ? round($products->avg('turnover'), 2) : 0,
                'high_rotation' => $products->where('turnover', '>=', 1)->count(),
                'low_rotation' => $products->where('turnover', '<', 0.5)->count(),
            ],
        ];
    }

    /**
     * 4. Productos con quiebre de stock (stockouts).
     * Productos que llegaron a stock 0 durante el período.
     * Usa el activity log de stock_update para detectar cuándo el stock llegó a 0.
     */
    public function stockouts(int $branchId, Carbon $startDate, Carbon $endDate, ?int $categoryId = null): array
    {
        // Buscar actividades de stock_update donde stock_after = 0
        $activities = DB::table('activity_log')
            ->where('subject_type', Product::class)
            ->where('event', 'stock_update')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereRaw("JSON_EXTRACT(properties, '$.stock_after') = 0")
            ->select('subject_id',
                DB::raw('COUNT(*) as stockout_count'),
                DB::raw('MIN(created_at) as first_stockout'),
                DB::raw('MAX(created_at) as last_stockout'))
            ->groupBy('subject_id')
            ->get();

        if ($activities->isEmpty()) {
            return [
                'title' => 'Productos con quiebre de stock',
                'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
                'headers' => ['Producto', 'SKU', 'Categoría', 'Veces en cero', 'Primer quiebre', 'Último quiebre', 'Stock actual'],
                'rows' => collect(),
                'summary' => ['total_stockouts' => 0, 'affected_products' => 0],
            ];
        }

        $productIds = $activities->pluck('subject_id');

        $query = Product::whereIn('id', $productIds)
            ->with(['category'])
            ->withSum(['branches' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }], 'branch_product.current_stock');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get()->keyBy('id');

        $results = $activities->map(function ($activity) use ($products) {
            $product = $products->get($activity->subject_id);
            if (!$product) return null;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'stockout_count' => (int) $activity->stockout_count,
                'first_stockout' => Carbon::parse($activity->first_stockout)->format('d/m/Y H:i'),
                'last_stockout' => Carbon::parse($activity->last_stockout)->format('d/m/Y H:i'),
                'current_stock' => (float) ($product->branches_sum_branch_productcurrent_stock ?? 0),
            ];
        })->filter()->sortByDesc('stockout_count')->values();

        return [
            'title' => 'Productos con quiebre de stock',
            'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
            'headers' => ['Producto', 'SKU', 'Categoría', 'Veces en cero', 'Primer quiebre', 'Último quiebre', 'Stock actual'],
            'rows' => $results,
            'summary' => [
                'total_stockouts' => $results->sum('stockout_count'),
                'affected_products' => $results->count(),
            ],
        ];
    }

    /**
     * 5. Valorización de inventario actual.
     * Stock disponible × costo unitario, agrupado por producto/categoría.
     */
    public function inventoryValuation(int $branchId, ?int $categoryId = null, string $groupBy = 'product'): array
    {
        $query = Product::query()
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->where('current_stock', '>', 0);
            })
            ->with(['category'])
            ->withSum(['branches' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }], 'branch_product.current_stock');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get()->map(function ($product) {
            $stock = (float) ($product->branches_sum_branch_productcurrent_stock ?? 0);
            $cost = (float) $product->cost_price;
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'current_stock' => $stock,
                'cost_price' => $cost,
                'total_value' => round($stock * $cost, 2),
                'selling_price' => (float) $product->selling_price,
                'potential_revenue' => round($stock * (float) $product->selling_price, 2),
            ];
        });

        if ($groupBy === 'category') {
            $grouped = $products->groupBy('category')->map(function ($items, $category) {
                return [
                    'category' => $category ?: 'Sin categoría',
                    'product_count' => $items->count(),
                    'total_stock' => round($items->sum('current_stock'), 2),
                    'total_value' => round($items->sum('total_value'), 2),
                    'potential_revenue' => round($items->sum('potential_revenue'), 2),
                ];
            })->sortByDesc('total_value')->values();

            return [
                'title' => 'Valorización de inventario por categoría',
                'subtitle' => 'Stock actual × costo unitario',
                'headers' => ['Categoría', 'Productos', 'Unidades totales', 'Valor de costo', 'Ingreso potencial'],
                'rows' => $grouped,
                'summary' => [
                    'total_products' => $products->count(),
                    'total_value' => round($products->sum('total_value'), 2),
                    'total_potential' => round($products->sum('potential_revenue'), 2),
                ],
            ];
        }

        $sorted = $products->sortByDesc('total_value')->values();

        return [
            'title' => 'Valorización de inventario actual',
            'subtitle' => 'Stock actual × costo unitario',
            'headers' => ['Producto', 'SKU', 'Categoría', 'Stock', 'Costo unit.', 'Valor total', 'Precio venta', 'Ingreso potencial'],
            'rows' => $sorted,
            'summary' => [
                'total_products' => $sorted->count(),
                'total_stock' => round($sorted->sum('current_stock'), 2),
                'total_value' => round($sorted->sum('total_value'), 2),
                'potential_revenue' => round($sorted->sum('potential_revenue'), 2),
            ],
        ];
    }

    /**
     * 6. Mercancía con mayor valor inmovilizado.
     * Combina "poco movimiento" + "alto costo × stock".
     */
    public function highValueStagnant(int $branchId, Carbon $startDate, Carbon $endDate, ?int $categoryId = null, int $limit = 30): array
    {
        // Productos que tuvieron movimiento
        $movedIds = TransactionItem::where('itemable_type', Product::class)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->pluck('itemable_id')
            ->toArray();

        $query = Product::query()
            ->whereNotIn('id', $movedIds)
            ->where('cost_price', '>', 0)
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->where('current_stock', '>', 0);
            })
            ->with(['category', 'brand'])
            ->withSum(['branches' => function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            }], 'branch_product.current_stock');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->get()->map(function ($product) use ($branchId) {
            $stock = (float) ($product->branches_sum_branch_productcurrent_stock ?? 0);
            $totalValue = $stock * (float) $product->cost_price;
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'current_stock' => $stock,
                'cost_price' => (float) $product->cost_price,
                'total_value' => round($totalValue, 2),
                'last_sale_date' => $this->getLastSaleDate($product->id, Product::class, $branchId),
                'days_without_sale' => $this->getDaysWithoutSale($product->id, Product::class, $branchId),
            ];
        })->sortByDesc('total_value')->take($limit)->values();

        return [
            'title' => 'Mercancía con mayor valor inmovilizado',
            'subtitle' => "Sin ventas del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')} · Top {$limit}",
            'headers' => ['Producto', 'SKU', 'Categoría', 'Marca', 'Stock', 'Costo unit.', 'Valor inmovilizado', 'Días sin venta'],
            'rows' => $products,
            'summary' => [
                'total_products' => $products->count(),
                'total_value' => round($products->sum('total_value'), 2),
                'avg_days_without_sale' => $products->isNotEmpty() ? round($products->avg('days_without_sale')) : 0,
            ],
        ];
    }

    /**
     * 7. Margen por producto en el período.
     * Cruza ventas con costo para ver utilidad real.
     */
    public function marginByProduct(int $branchId, Carbon $startDate, Carbon $endDate, ?int $categoryId = null, int $limit = 50): array
    {
        $items = TransactionItem::where('itemable_type', Product::class)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado')
                ->whereBetween('created_at', [$startDate, $endDate]))
            ->select('itemable_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(line_total) as total_revenue'))
            ->groupBy('itemable_id')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            return [
                'title' => 'Margen por producto',
                'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
                'headers' => ['Producto', 'SKU', 'Categoría', 'Vendido', 'Ingreso', 'Costo total', 'Margen bruto', 'Margen %'],
                'rows' => collect(),
                'summary' => ['total_revenue' => 0, 'total_cost' => 0, 'total_margin' => 0, 'avg_margin_pct' => 0],
            ];
        }

        $productIds = $items->pluck('itemable_id');
        $products = Product::whereIn('id', $productIds)
            ->with('category')
            ->get()
            ->keyBy('id');

        $results = $items->map(function ($item) use ($products) {
            $product = $products->get($item->itemable_id);
            if (!$product) return null;

            $totalRevenue = (float) $item->total_revenue;
            $totalQuantity = (float) $item->total_quantity;
            $costPrice = (float) $product->cost_price;
            $totalCost = round($totalQuantity * $costPrice, 2);
            $margin = round($totalRevenue - $totalCost, 2);
            $marginPct = $totalRevenue > 0 ? round(($margin / $totalRevenue) * 100, 1) : 0;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'total_quantity' => $totalQuantity,
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'margin' => $margin,
                'margin_pct' => $marginPct,
            ];
        })->filter()->values();

        return [
            'title' => 'Margen por producto',
            'subtitle' => "Del {$startDate->format('d/m/Y')} al {$endDate->format('d/m/Y')}",
            'headers' => ['Producto', 'SKU', 'Categoría', 'Vendido', 'Ingreso', 'Costo total', 'Margen bruto', 'Margen %'],
            'rows' => $results,
            'summary' => [
                'total_revenue' => round($results->sum('total_revenue'), 2),
                'total_cost' => round($results->sum('total_cost'), 2),
                'total_margin' => round($results->sum('margin'), 2),
                'avg_margin_pct' => $results->isNotEmpty() ? round($results->avg('margin_pct'), 1) : 0,
            ],
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function getLastSaleDate(int $productId, string $type, int $branchId): ?string
    {
        $lastSale = TransactionItem::where('itemable_id', $productId)
            ->where('itemable_type', $type)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado'))
            ->latest()
            ->first();

        return $lastSale?->created_at?->format('d/m/Y');
    }

    private function getDaysWithoutSale(int $productId, string $type, int $branchId): int
    {
        $lastSale = TransactionItem::where('itemable_id', $productId)
            ->where('itemable_type', $type)
            ->whereHas('transaction', fn($q) => $q->where('branch_id', $branchId)
                ->where('status', 'completado'))
            ->latest()
            ->first();

        if (!$lastSale) {
            // Nunca se ha vendido: usar la fecha de creación del producto
            $product = Product::find($productId);
            return $product ? (int) $product->created_at->diffInDays(now()) : 0;
        }

        return (int) $lastSale->created_at->diffInDays(now());
    }
}
