<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\GlobalProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GlobalProduct>
 */
class GlobalProductFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = GlobalProduct::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->paragraph(),
            'sku' => $this->faker->unique()->ean13(),
            'selling_price' => $this->faker->randomFloat(2, 50, 5000),
            'measure_unit' => $this->faker->randomElement(['pieza', 'kg', 'paquete', 'litro']),
            
            // Las relaciones se asignarán en el seeder para asegurar la coherencia,
            // pero se pueden definir aquí como fallback si se usa la factory directamente.
            'category_id' => null, 
            'brand_id' => null,
            'business_type_id' => null,
        ];
    }
}