<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuoteFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 100, 10000);
        $discount = $this->faker->randomFloat(2, 0, $subtotal * 0.2);
        
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'folio' => 'COT-' . $this->faker->unique()->numberBetween(1000, 9999),
            'status' => $this->faker->randomElement(QuoteStatus::class),
            'expiry_date' => $this->faker->dateTimeBetween('now', '+1 month'),
            'subtotal' => $subtotal,
            'total_discount' => $discount,
            'total_amount' => $subtotal - $discount,
            'version_number' => 1,
        ];
    }
}