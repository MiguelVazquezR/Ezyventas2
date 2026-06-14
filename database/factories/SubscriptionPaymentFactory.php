<?php

namespace Database\Factories;

use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    public function definition(): array
    {
        return [
            'subscription_version_id' => SubscriptionVersion::factory(),
            'amount' => $this->faker->randomFloat(2, 500, 5000),
            'payment_method' => 'transfer',
            'invoiced' => false,
            'invoice_status' => 'no_solicitada',
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => 'approved',
        ]);
    }
}
