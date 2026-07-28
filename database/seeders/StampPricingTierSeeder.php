<?php

namespace Database\Seeders;

use App\Models\Billing\StampPricingTier;
use Illuminate\Database\Seeder;

class StampPricingTierSeeder extends Seeder
{
    public function run(): void
    {
        StampPricingTier::query()->delete();

        $tiers = [
            [
                'min_quantity' => 1,
                'max_quantity' => 99,
                'unit_price'   => 0.8500,  // ~$0.85 MXN per stamp — placeholder, adjust after
                'label'        => 'Básico',
                'sort_order'   => 1,
            ],
            [
                'min_quantity' => 100,
                'max_quantity' => 499,
                'unit_price'   => 0.7650,  // ~10% descuento
                'label'        => 'Volumen medio',
                'sort_order'   => 2,
            ],
            [
                'min_quantity' => 500,
                'max_quantity' => null,     // sin límite superior
                'unit_price'   => 0.6800,  // ~20% descuento
                'label'        => 'Volumen alto',
                'sort_order'   => 3,
            ],
        ];

        foreach ($tiers as $tier) {
            StampPricingTier::create($tier);
        }
    }
}
