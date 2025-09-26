<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Servicio de ' . $this->faker->bs();
        return [
            'branch_id' => Branch::factory(),
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $this->faker->paragraph(),
            'base_price' => $this->faker->randomFloat(2, 100, 5000),
            'duration_estimate' => $this->faker->randomElement(['1-2 horas', '3-5 días hábiles', '1 semana']),
            'show_online' => $this->faker->boolean(),
        ];
    }
}