<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Variant stock movements are mirrored into the parent product pivot (branch_product), so
     * the parent must hold the sum of its variants. Historically it was seeded to zero, which
     * drifted it to negative values after sales and even broke the product edit form. This
     * recalculates the parent pivot as the real sum of the variants for each branch.
     */
    public function up(): void
    {
        DB::table('branch_product')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_attributes')
                    ->whereColumn('product_attributes.product_id', 'branch_product.product_id');
            })
            ->update([
                'current_stock' => DB::raw('(
                    SELECT COALESCE(SUM(bpa.current_stock), 0)
                    FROM branch_product_attribute bpa
                    JOIN product_attributes pa ON pa.id = bpa.product_attribute_id
                    WHERE pa.product_id = branch_product.product_id
                      AND bpa.branch_id = branch_product.branch_id
                )'),
                'reserved_stock' => DB::raw('(
                    SELECT COALESCE(SUM(bpa.reserved_stock), 0)
                    FROM branch_product_attribute bpa
                    JOIN product_attributes pa ON pa.id = bpa.product_attribute_id
                    WHERE pa.product_id = branch_product.product_id
                      AND bpa.branch_id = branch_product.branch_id
                )'),
            ]);
    }

    /**
     * Data repair only. The previous (inconsistent) values are intentionally not restored.
     */
    public function down(): void
    {
        //
    }
};
