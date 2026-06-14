<?php

namespace Database\Factories;

use App\Models\ReferralSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralSettingsFactory extends Factory
{
    protected $model = ReferralSettings::class;

    public function definition(): array
    {
        return [
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ];
    }
}
