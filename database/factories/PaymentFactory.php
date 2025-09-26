<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 1500),
            'payment_method' => $this->faker->randomElement(PaymentMethod::class),
            'payment_date' => now(),
            'status' => PaymentStatus::COMPLETED,
        ];
    }
}