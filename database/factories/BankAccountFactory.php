<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'bank_name' => $this->faker->randomElement(['BBVA', 'Santander', 'Banorte', 'HSBC']),
            'owner_name' => $this->faker->name(), // <-- AÑADIR ESTA LÍNEA
            'account_name' => 'Cuenta Principal',
            'account_number' => $this->faker->numerify('##################'),
            'balance' => $this->faker->randomFloat(2, 5000, 100000),
        ];
    }

    /**
     * Configure the model factory.
     *
     * @return $this
     */
    // public function configure()
    // {
    //     return $this->afterCreating(function ($bankAccount) {
    //         // Después de crear la cuenta, la asignamos a al menos una sucursal de su suscripción.
    //         $branches = Branch::where('subscription_id', $bankAccount->subscription_id)->get();
    //         if ($branches->isNotEmpty()) {
    //             // Asigna la cuenta a un número aleatorio de sucursales (al menos una)
    //             $bankAccount->branches()->attach(
    //                 $branches->random(rand(1, $branches->count()))->pluck('id')->toArray()
    //             );
    //         }
    //     });
    // }
}
