<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdjustLayawayStockAction
{
    /**
     * Ajusta las cantidades de apartados (reserved) y disponible (available)
     * de un producto o de sus variantes en la sucursal del usuario.
     *
     * La cantidad disponible es un valor calculado: available = current - reserved.
     * Por lo tanto, al ajustar "available" directamente, recalculamos el stock
     * fisico como current = available + reserved.
     */
    public function execute(Product $product, array $validated, User $user): void
    {
        $branchId = $user->branch_id;

        DB::transaction(function () use ($product, $validated, $branchId, $user) {
            $this->adjustProduct($product, $validated, $branchId, $user);

            if (!empty($validated['variants'])) {
                foreach ($validated['variants'] as $variantData) {
                    $variant = ProductAttribute::find($variantData['id']);
                    if ($variant) {
                        $this->adjustVariant($variant, $variantData, $branchId, $user);
                    }
                }
            }
        });
    }

    private function adjustProduct(Product $product, array $data, int $branchId, User $user): void
    {
        $pivotQuery = DB::table('branch_product')
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId);

        $current = $pivotQuery->first();
        $oldCurrentStock = $current ? (float) $current->current_stock : 0;
        $oldReservedStock = $current ? (float) $current->reserved_stock : 0;
        $oldAvailableStock = max(0, $oldCurrentStock - $oldReservedStock);

        $newReserved = $data['reserved_stock'] ?? null;
        $newAvailable = $data['available_stock'] ?? null;

        $changes = [];
        $nextReserved = $oldReservedStock;

        if ($newReserved !== null) {
            $changes['reserved_stock'] = $newReserved;
            $nextReserved = (float) $newReserved;
        }

        if ($newAvailable !== null) {
            // available = current - reserved  =>  current = available + reserved
            $changes['current_stock'] = (float) $newAvailable + $nextReserved;
        }

        if (empty($changes)) {
            return;
        }

        $pivotQuery->update($changes);

        $after = $pivotQuery->first();
        $afterReserved = $after ? (float) $after->reserved_stock : 0;
        $afterCurrent = $after ? (float) $after->current_stock : 0;
        $afterAvailable = max(0, $afterCurrent - $afterReserved);

        $propertyParts = [];

        if ($newReserved !== null) {
            $propertyParts[] = 'apartados: ' . $oldReservedStock . ' -> ' . $afterReserved;
        }

        if ($newAvailable !== null) {
            $propertyParts[] = 'disponible: ' . $oldAvailableStock . ' -> ' . $afterAvailable;
        }

        if (!empty($propertyParts)) {
            activity()->performedOn($product)
                ->causedBy($user)
                ->event('stock_update')
                ->withProperties([
                    'quantity_changed' => null,
                    'stock_before' => null,
                    'stock_after' => null,
                    'stock_field' => 'layaway_adjustment',
                    'reserved_before' => $oldReservedStock,
                    'reserved_after' => $afterReserved,
                    'available_before' => $oldAvailableStock,
                    'available_after' => $afterAvailable,
                ])
                ->log('Ajuste manual de apartados y disponible: ' . implode(', ', $propertyParts));
        }
    }

    private function adjustVariant(ProductAttribute $variant, array $data, int $branchId, User $user): void
    {
        $variantQuery = DB::table('branch_product_attribute')
            ->where('product_attribute_id', $variant->id)
            ->where('branch_id', $branchId);

        $current = $variantQuery->first();
        $oldCurrentStock = $current ? (float) $current->current_stock : 0;
        $oldReservedStock = $current ? (float) $current->reserved_stock : 0;
        $oldAvailableStock = max(0, $oldCurrentStock - $oldReservedStock);

        $newReserved = $data['reserved_stock'] ?? null;
        $newAvailable = $data['available_stock'] ?? null;

        $variantChanges = [];
        $nextReserved = $oldReservedStock;

        if ($newReserved !== null) {
            $variantChanges['reserved_stock'] = $newReserved;
            $nextReserved = (float) $newReserved;
        }

        if ($newAvailable !== null) {
            $variantChanges['current_stock'] = (float) $newAvailable + $nextReserved;
        }

        if (empty($variantChanges)) {
            return;
        }

        $variantQuery->update($variantChanges);

        // Sincronizar el total en el producto padre
        $this->syncParentTotals($variant->product_id, $branchId);

        $after = $variantQuery->first();
        $afterReserved = $after ? (float) $after->reserved_stock : 0;
        $afterCurrent = $after ? (float) $after->current_stock : 0;
        $afterAvailable = max(0, $afterCurrent - $afterReserved);

        $propertyParts = [];

        if ($newReserved !== null) {
            $propertyParts[] = 'apartados: ' . $oldReservedStock . ' -> ' . $afterReserved;
        }

        if ($newAvailable !== null) {
            $propertyParts[] = 'disponible: ' . $oldAvailableStock . ' -> ' . $afterAvailable;
        }

        if (!empty($propertyParts)) {
            $parentProduct = $variant->product ?? Product::find($variant->product_id);

            if ($parentProduct) {
                activity()->performedOn($parentProduct)
                    ->causedBy($user)
                    ->event('stock_update')
                    ->withProperties([
                        'quantity_changed' => null,
                        'stock_before' => null,
                        'stock_after' => null,
                        'stock_field' => 'layaway_adjustment',
                        'reserved_before' => $oldReservedStock,
                        'reserved_after' => $afterReserved,
                        'available_before' => $oldAvailableStock,
                        'available_after' => $afterAvailable,
                    ])
                    ->log('Ajuste manual de apartados y disponible [Variante: ' . implode(' ', $variant->attributes ?? []) . ']: ' . implode(', ', $propertyParts));
            }
        }
    }

    private function syncParentTotals(int $productId, int $branchId): void
    {
        $totals = DB::table('branch_product_attribute')
            ->join('product_attributes', 'product_attributes.id', '=', 'branch_product_attribute.product_attribute_id')
            ->where('product_attributes.product_id', $productId)
            ->where('branch_product_attribute.branch_id', $branchId)
            ->selectRaw('SUM(branch_product_attribute.current_stock) as total_current, SUM(branch_product_attribute.reserved_stock) as total_reserved')
            ->first();

        DB::table('branch_product')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->update([
                'current_stock' => $totals?->total_current ?? 0,
                'reserved_stock' => $totals?->total_reserved ?? 0,
            ]);
    }
}