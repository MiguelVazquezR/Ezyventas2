<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionVersionFactory extends Factory
{
    protected $model = SubscriptionVersion::class;

    public function definition(): array
    {
        $startDate = Carbon::instance($this->faker->dateTimeBetween('-1 month', 'now'));

        return [
            'subscription_id' => Subscription::factory(),
            'start_date' => $startDate,
            'end_date' => $startDate->copy()->addMonth(),
        ];
    }
}
