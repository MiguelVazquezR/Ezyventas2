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
        $minStock = $this->faker->numberBetween(5, 20);
        $currentStock = $this->faker->numberBetween(0, 150);
        $name = ucfirst($this->faker->words(2, true));

        return [
            'name' => $name,
            'description' => $this->faker->paragraph(),
            'sku' => $this->faker->unique()->ean8(),
            'slug' => Str::slug($name),
            'selling_price' => $sellingPrice,
            'cost_price' => $sellingPrice * $this->faker->randomFloat(2, 0.5, 0.8),
            'current_stock' => $currentStock,
            'min_stock' => $minStock,
            'max_stock' => $this->faker->numberBetween(200, 500),
            'measure_unit' => $this->faker->randomElement(['unit', 'kg', 'ltr', 'pair']),
            'currency' => 'MXN',
            'requires_shipping' => $this->faker->boolean(90),
        ];
    }
}