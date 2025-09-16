<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $company = $this->faker->company();
        return [
            'business_name' => $company . ' S.A. de C.V.',
            'commercial_name' => $company,
            'slug' => Str::slug($company),
            'status' => 'activo',
            'contact_phone' => $this->faker->phoneNumber(),
            'contact_email' => $this->faker->unique()->safeEmail(),
            'tax_id' => $this->faker->unique()->regexify('[A-Z]{4}[0-9]{6}[A-Z0-9]{3}'),
            'address' => [
                'street' => $this->faker->streetAddress,
                'city' => $this->faker->city,
                'state' => $this->faker->state,
                'zip' => $this->faker->postcode,
                'country' => 'MX',
            ],
        ];
    }
}