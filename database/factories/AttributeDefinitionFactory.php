<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttributeDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'category_id' => Category::factory(),
            'name' => $this->faker->word(),
            'requires_image' => false, // Por defecto, no requiere imagen
        ];
    }
}
