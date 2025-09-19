<?php

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(), // <-- Añadido
            'expense_category_id' => ExpenseCategory::factory(),
            'amount' => $this->faker->randomFloat(2, 50, 3000),
            'expense_date' => $this->faker->dateTimeBetween('-3 months', 'now'),
            'status' => $this->faker->randomElement(ExpenseStatus::class),
            'description' => $this->faker->sentence(6),
            'folio' => 'G-' . $this->faker->unique()->randomNumber(6),
        ];
    }
}