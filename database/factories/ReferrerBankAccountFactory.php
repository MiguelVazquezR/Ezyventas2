<?php

namespace Database\Factories;

use App\Models\ReferrerBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferrerBankAccountFactory extends Factory
{
    protected $model = ReferrerBankAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'clabe' => $this->faker->numerify('#################0'),
            'bank_name' => $this->faker->randomElement(['BBVA', 'Santander', 'Banamex', 'Banorte', 'HSBC']),
            'account_holder_name' => $this->faker->name(),
        ];
    }
}
