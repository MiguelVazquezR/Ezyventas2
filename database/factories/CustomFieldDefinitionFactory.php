<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomFieldDefinition>
 */
class CustomFieldDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'subscription_id' => Subscription::factory(),
            'module' => 'service_orders', // Por defecto para este módulo
            'name' => Str::ucfirst($name),
            'key' => Str::slug($name, '_'),
            'type' => $this->faker->randomElement(['text', 'number', 'boolean', 'textarea']),
            'is_required' => $this->faker->boolean(20), // 20% de probabilidad de ser requerido
        ];
    }
}