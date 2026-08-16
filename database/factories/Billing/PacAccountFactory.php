<?php

namespace Database\Factories\Billing;

use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Models\Billing\PacAccount;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class PacAccountFactory extends Factory
{
    protected $model = PacAccount::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'provider'        => 'sw_sapien',
            'account_type'    => PacAccountType::SUBACCOUNT,
            'status'          => PacAccountStatus::ACTIVE,
            'sw_user_id'      => 'SW' . $this->faker->unique()->numerify('#########'),
            'login_email'     => $this->faker->unique()->safeEmail(),
            'password'        => 'secret-test-password',
            'requested_at'    => now(),
            'activated_at'    => now(),
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => [
            'account_type' => PacAccountType::SHARED,
            'sw_user_id'   => null,
        ]);
    }

    public function pendingRequest(): static
    {
        return $this->state(fn () => [
            'status'       => PacAccountStatus::PENDING_REQUEST,
            'login_email'  => null,
            'password'     => null,
        ]);
    }
}
