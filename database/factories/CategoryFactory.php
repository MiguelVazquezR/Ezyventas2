<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'name' => ucfirst($this->faker->words(2, true)),
            'business_type' => $this->faker->randomElement(['retail', 'services']),
        ];
    }
}