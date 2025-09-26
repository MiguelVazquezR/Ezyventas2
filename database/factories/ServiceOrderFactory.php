<?php

namespace Database\Factories;

use App\Enums\ServiceOrderStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->safeEmail(),
            'customer_phone' => $this->faker->phoneNumber(),
            'technician_name' => $this->faker->optional()->name(),
            'status' => $this->faker->randomElement(ServiceOrderStatus::class),
            'received_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'promised_at' => $this->faker->dateTimeBetween('now', '+2 weeks'),
            'item_description' => $this->faker->randomElement(['iPhone 13 Pro', 'Laptop HP Pavilion', 'Compresor 50L', 'Samsung Galaxy S22']),
            'reported_problems' => $this->faker->sentence(),
            'final_total' => $this->faker->optional()->randomFloat(2, 300, 8000),
            // Ejemplo de campos personalizados
            'custom_fields' => $this->faker->randomElement([
                ['pin_desbloqueo' => '1234', 'incluye_cargador' => true],
                ['potencia_motor_hp' => 5.5, 'capacidad_tanque_gal' => 50],
            ]),
        ];
    }
}