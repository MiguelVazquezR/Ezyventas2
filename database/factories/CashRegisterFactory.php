<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CashRegister>
 */
class CashRegisterFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CashRegister::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Caja ' . $this->faker->unique()->randomDigitNotNull(),
            'is_active' => true,
            'in_use' => $this->faker->boolean(20), // 20% de probabilidad de que esté en uso
        ];
    }
}