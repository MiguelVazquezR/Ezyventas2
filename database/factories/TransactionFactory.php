<?php

namespace Database\Factories;

use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomFloat(2, 10, 1500);
        $discount = $this->faker->randomFloat(2, 0, $subtotal * 0.1); // Max 10% discount

        return [
            'folio' => 'V-' . $this->faker->unique()->numberBetween(1000, 99999),
            'customer_id' => Customer::factory(),
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'cash_register_session_id' => CashRegisterSession::factory(),
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => $subtotal,
            'total_discount' => $discount,
            'total_tax' => 0, // Asumimos 0 por ahora para simplificar
            'currency' => 'MXN',
            'invoiced' => $this->faker->boolean(10),
            'created_at' => $this->faker->dateTimeBetween('-3 months', 'now'),
        ];
    }
}