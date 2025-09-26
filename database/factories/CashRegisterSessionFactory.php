<?php

namespace Database\Factories;

use App\Enums\CashRegisterSessionStatus;
use App\Models\CashRegister;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashRegisterSession>
 */
class CashRegisterSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $openedAt = $this->faker->dateTimeBetween('-3 months', 'now');
        $closedAt = Carbon::instance($openedAt)->addHours($this->faker->numberBetween(8, 10));

        $openingBalance = $this->faker->randomFloat(2, 500, 1500);
        
        // Simular una pequeña diferencia en el conteo final
        $difference = $this->faker->randomFloat(2, -50, 50);

        return [
            'cash_register_id' => CashRegister::factory(),
            'user_id' => User::factory(),
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'status' => CashRegisterSessionStatus::CLOSED,
            'opening_cash_balance' => $openingBalance,
            'closing_cash_balance' => null, // Se calculará en el seeder
            'calculated_cash_total' => null, // Se calculará en el seeder
            'cash_difference' => $difference,
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}