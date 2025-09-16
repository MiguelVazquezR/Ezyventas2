<?php

namespace Database\Factories;

use App\Models\AttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AttributeOption>
 */
class AttributeOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attribute_definition_id' => AttributeDefinition::factory(),
            'value' => $this->faker->word(), // e.g., 'Rojo'
        ];
    }
}