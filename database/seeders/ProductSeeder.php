<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crea 150 productos de ejemplo utilizando la factory.
        // Esto es ideal para probar la paginación y los filtros.
        Product::factory()->count(150)->create();
    }
}
