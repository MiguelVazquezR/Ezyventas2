<?php

namespace Database\Seeders;

use App\Models\BusinessType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {        
        $types = [
            ['name' => 'Tienda de Ropa y Accesorios'],
            ['name' => 'Tienda de Electrónica'],
            ['name' => 'Restaurante o Cafetería'],
            ['name' => 'Tienda de Abarrotes'],
            ['name' => 'Servicios Profesionales'],
        ];

        BusinessType::insert($types);
    }
}