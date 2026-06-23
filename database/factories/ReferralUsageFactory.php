<?php

namespace Database\Factories;

use App\Models\ReferralCode;
use App\Models\ReferralUsage;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralUsageFactory extends Factory
{
    protected $model = ReferralUsage::class;

    public function definition(): array
    {
        return [
            'referral_code_id' => ReferralCode::factory(),
            'referred_subscription_id' => Subscription::factory(),
            'subscription_payment_id' => SubscriptionPayment::factory(),
            'reward_status' => 'pending',
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
            'monthly_base_amount' => 1000.00,
            'reward_amount' => 500.00,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn(array $attributes) => [
            'reward_status' => 'paid',
            'reward_paid_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn(array $attributes) => [
            'reward_status' => 'cancelled',
        ]);
    }
}
