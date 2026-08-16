<?php

namespace Database\Factories\Billing;

use App\Models\Branch;
use App\Models\Billing\FiscalProfile;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class FiscalProfileFactory extends Factory
{
    protected $model = FiscalProfile::class;

    public function definition(): array
    {
        return [
            'subscription_id'    => Subscription::factory(),
            'pac_account_id'     => null, // los tests la vinculan explícitamente a una cuenta
            'rfc'                => $this->faker->unique()->regexify('[A-Z]{4}[0-9]{6}[A-Z0-9]{3}'),
            'razon_social'       => $this->faker->company(),
            'regimen_fiscal'     => '601',
            'postal_code'        => $this->faker->numerify('#####'),
            'email'              => $this->faker->unique()->safeEmail(),
            'is_active'          => true,
            'manifest_signed_at' => now(),
        ];
    }

    public function onBranch(Branch $branch): static
    {
        return $this->state(fn () => [
            'subscription_id' => $branch->subscription_id,
        ]);
    }
}
