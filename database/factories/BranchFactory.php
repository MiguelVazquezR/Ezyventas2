<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'manager_id' => null, // Se puede asignar en el seeder si es necesario
            'name' => 'Sucursal ' . $this->faker->city(),
            'is_main' => false,
            'contact_phone' => $this->faker->phoneNumber(),
            'contact_email' => $this->faker->safeEmail(),
            'timezone' => $this->faker->timezone(),
        ];
    }
}