<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'name' => $this->faker->randomElement(['Renta', 'Servicios Públicos', 'Nómina', 'Marketing', 'Insumos de Oficina']),
            'description' => $this->faker->sentence(),
        ];
    }
}