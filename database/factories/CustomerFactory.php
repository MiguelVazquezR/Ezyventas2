<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => $this->faker->name(),
            'company_name' => $this->faker->optional()->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'tax_id' => $this->faker->optional()->regexify('[A-Z]{4}[0-9]{6}[A-Z0-9]{3}'),
            // Genera un balance que puede ser deuda (negativo) or crédito (positivo)
            'balance' => $this->faker->randomFloat(2, -1000, 1000),
            'credit_limit' => $this->faker->randomElement([0, 500, 1000, 2000]),
        ];
    }
}