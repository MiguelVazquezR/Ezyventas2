<?php

namespace Database\Factories;

use App\Enums\SessionCashMovementType;
use App\Models\CashRegisterSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SessionCashMovement>
 */
class SessionCashMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_register_session_id' => CashRegisterSession::factory(),
            'type' => $this->faker->randomElement(SessionCashMovementType::class),
            'amount' => $this->faker->randomFloat(2, 10, 500), // Genera un monto entre 10 y 500
            'description' => $this->faker->sentence(4), // Genera una descripción corta
        ];
    }
}