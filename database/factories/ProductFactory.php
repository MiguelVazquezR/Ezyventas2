<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $sellingPrice = $this->faker->randomFloat(2, 10, 2500);
        $name = ucfirst($this->faker->words(2, true));

        return [
            'name' => $name,
            'description' => $this->faker->paragraph(),
            'sku' => $this->faker->unique()->ean8(),
            'slug' => Str::slug($name),
            'selling_price' => $sellingPrice,
            'cost_price' => $sellingPrice * $this->faker->randomFloat(2, 0.5, 0.8),
            // 'current_stock', 'min_stock' y 'max_stock' fueron eliminados de aquí
            // ya que ahora se gestionan en la tabla pivote `branch_product`.
            'measure_unit' => $this->faker->randomElement(['unit', 'kg', 'ltr', 'pair']),
            'currency' => 'MXN',
            'requires_shipping' => $this->faker->boolean(90),
        ];
    }

    /**
     * Estado para adjuntar stock inicial a una sucursal inmediatamente después de crear el producto.
     * Uso en tests: Product::factory()->withStock($branchId, 20)->create();
     */
    public function withStock(int $branchId, int $currentStock = 10, int $reservedStock = 0, int $minStock = 5, int $maxStock = 200)
    {
        return $this->afterCreating(function (Product $product) use ($branchId, $currentStock, $reservedStock, $minStock, $maxStock) {
            $product->branches()->attach($branchId, [
                'current_stock' => $currentStock,
                'reserved_stock' => $reservedStock,
                'min_stock' => $minStock,
                'max_stock' => $maxStock,
            ]);
        });
    }
}