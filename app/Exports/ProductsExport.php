<?php

namespace App\Exports;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProductsExport implements FromArray, WithHeadings, ShouldAutoSize
{
    public function array(): array
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        $branchIds = Branch::where('subscription_id', $subscriptionId)->pluck('id');

        $products = Product::whereHas('branch.subscription', function ($query) use ($subscriptionId) {
            $query->where('id', $subscriptionId);
        })
            ->whereNull('global_product_id')
            ->with(['category', 'brand', 'provider', 'productAttributes', 'components'])
            ->get();

        // Pre-load stock aggregates from branch_product pivot
        $productStocks = DB::table('branch_product')
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_id', $products->pluck('id'))
            ->select(
                'product_id',
                DB::raw('SUM(current_stock) as total_stock'),
                DB::raw('SUM(reserved_stock) as total_reserved'),
                DB::raw('GROUP_CONCAT(DISTINCT location SEPARATOR ", ") as locations')
            )
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        // Pre-load variant stock aggregates
        $variantIds = $products->flatMap(fn($p) => $p->productAttributes->pluck('id'))->filter();
        $variantStocks = collect();

        if ($variantIds->isNotEmpty()) {
            $variantStocks = DB::table('branch_product_attribute')
                ->whereIn('branch_id', $branchIds)
                ->whereIn('product_attribute_id', $variantIds)
                ->select(
                    'product_attribute_id',
                    DB::raw('SUM(current_stock) as total_stock'),
                    DB::raw('SUM(reserved_stock) as total_reserved')
                )
                ->groupBy('product_attribute_id')
                ->get()
                ->keyBy('product_attribute_id');
        }

        $data = [];

        foreach ($products as $product) {
            $stockInfo = $productStocks->get($product->id);
            $totalStock = $stockInfo ? (float) $stockInfo->total_stock : 0;
            $totalReserved = $stockInfo ? (float) $stockInfo->total_reserved : 0;
            $locations = $stockInfo?->locations ?? '';

            $type = match (true) {
                $product->components->isNotEmpty() => 'Kit/Combo',
                $product->productAttributes->isNotEmpty() => 'Con variantes',
                $product->is_bulk => 'Granel',
                default => 'Simple',
            };

            if ($product->productAttributes->isNotEmpty()) {
                foreach ($product->productAttributes as $attribute) {
                    $vStockInfo = $variantStocks->get($attribute->id);
                    $vTotalStock = $vStockInfo ? (float) $vStockInfo->total_stock : 0;
                    $vTotalReserved = $vStockInfo ? (float) $vStockInfo->total_reserved : 0;
                    $variantPrice = (float) $product->selling_price + (float) $attribute->selling_price_modifier;

                    $data[] = [
                        // -- Info general del producto padre --
                        $product->id,
                        $product->name,
                        $product->sku,
                        $product->description,
                        $product->category->name ?? '',
                        $product->brand->name ?? '',
                        $product->provider->name ?? '',
                        // -- Tipo --
                        $type,
                        // -- Precios --
                        (float) $product->selling_price,
                        (float) $product->cost_price,
                        $product->price_tiers ? json_encode($product->price_tiers) : '',
                        // -- Variante --
                        implode(' | ', array_map(fn($k, $v) => "$k: $v", array_keys($attribute->attributes ?? []), $attribute->attributes ?? [])),
                        $product->sku . '-' . $attribute->sku_suffix,
                        $variantPrice,
                        // -- Stock --
                        $vTotalStock,
                        $vTotalReserved,
                        $vTotalStock - $vTotalReserved,
                        // -- Unidad y visibilidad --
                        $product->measure_unit,
                        $product->show_in_pos ? 'Sí' : 'No',
                        $product->show_online ? 'Sí' : 'No',
                        (float) $product->online_price,
                        $product->is_featured ? 'Sí' : 'No',
                        // -- Oferta --
                        $product->is_on_sale ? 'Sí' : 'No',
                        (float) $product->sale_price,
                        $product->sale_start_date?->format('Y-m-d') ?? '',
                        $product->sale_end_date?->format('Y-m-d') ?? '',
                        // -- Logística --
                        (float) $product->weight,
                        (float) $product->length,
                        (float) $product->width,
                        (float) $product->height,
                        $product->requires_shipping ? 'Sí' : 'No',
                        $product->delivery_days,
                        // -- Analíticas --
                        $product->view_count,
                        $product->purchase_count,
                        // -- Etiquetas y ubicación --
                        is_array($product->tags) ? implode(', ', $product->tags) : $product->tags,
                        $locations,
                        // -- Orden tienda --
                        $product->store_sort_order ?? 0,
                    ];
                }
            } else {
                $data[] = [
                    $product->id,
                    $product->name,
                    $product->sku,
                    $product->description,
                    $product->category->name ?? '',
                    $product->brand->name ?? '',
                    $product->provider->name ?? '',
                    $type,
                    (float) $product->selling_price,
                    (float) $product->cost_price,
                    $product->price_tiers ? json_encode($product->price_tiers) : '',
                    // Sin variante
                    '',
                    '',
                    '',
                    // Stock
                    $totalStock,
                    $totalReserved,
                    $totalStock - $totalReserved,
                    // Unidad y visibilidad
                    $product->measure_unit,
                    $product->show_in_pos ? 'Sí' : 'No',
                    $product->show_online ? 'Sí' : 'No',
                    (float) $product->online_price,
                    $product->is_featured ? 'Sí' : 'No',
                    // Oferta
                    $product->is_on_sale ? 'Sí' : 'No',
                    (float) $product->sale_price,
                    $product->sale_start_date?->format('Y-m-d') ?? '',
                    $product->sale_end_date?->format('Y-m-d') ?? '',
                    // Logística
                    (float) $product->weight,
                    (float) $product->length,
                    (float) $product->width,
                    (float) $product->height,
                    $product->requires_shipping ? 'Sí' : 'No',
                    $product->delivery_days,
                    // Analíticas
                    $product->view_count,
                    $product->purchase_count,
                    // Etiquetas y ubicación
                    is_array($product->tags) ? implode(', ', $product->tags) : $product->tags,
                    $locations,
                    // Orden tienda
                    $product->store_sort_order ?? 0,
                ];
            }
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'SKU',
            'Descripción',
            'Categoría',
            'Marca',
            'Proveedor',
            'Tipo',
            'Precio de venta',
            'Precio de costo',
            'Precios por escala (JSON)',
            'Atributos variante',
            'SKU variante',
            'Precio variante',
            'Stock total',
            'Stock reservado',
            'Stock disponible',
            'Unidad de medida',
            'Visible en POS',
            'Visible en tienda en línea',
            'Precio en línea',
            'Destacado',
            'En oferta',
            'Precio de oferta',
            'Inicio de oferta',
            'Fin de oferta',
            'Peso (kg)',
            'Largo (cm)',
            'Ancho (cm)',
            'Alto (cm)',
            'Requiere envío',
            'Días de entrega',
            'Visitas',
            'Compras',
            'Etiquetas',
            'Ubicaciones',
            'Orden en tienda',
        ];
    }
}