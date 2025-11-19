<?php

namespace Database\Factories;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PrintTemplate>
 */
class PrintTemplateFactory extends Factory
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
            'name' => $this->faker->words(3, true),
            'type' => TemplateType::SALE_TICKET,
            'context_type' => TemplateContextType::GENERAL,
            'content' => [
                'config' => ['paperWidth' => '80mm'],
                'elements' => [
                    ['type' => 'text', 'data' => ['text' => 'Hola Mundo']]
                ]
            ],
            'is_default' => false,
        ];
    }
}