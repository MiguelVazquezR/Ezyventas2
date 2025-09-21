<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'bank_name' => $this->faker->randomElement(['BBVA', 'Santander', 'Banorte', 'HSBC']),
            'account_name' => 'Cuenta Principal',
            'account_number' => $this->faker->numerify('############'),
            'balance' => $this->faker->randomFloat(2, 5000, 100000),
        ];
    }
}