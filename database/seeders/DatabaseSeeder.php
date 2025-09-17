<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Models\BusinessType;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Llenar catálogos base
        $this->call(BusinessTypeSeeder::class);
        $this->seedGlobalBrands();

        // 2. Crear Suscriptores y sus datos privados
        $businessTypes = BusinessType::all();

        // Creamos 2 Suscripciones
        Subscription::factory(2)->create()->each(function ($subscription) use ($businessTypes) {
            // Asignar un tipo de negocio aleatorio a la suscripción
            $subscription->business_type_id = $businessTypes->random()->id;
            $subscription->save();

            // 1. Crear 1 Usuario admin por Suscriptor
            User::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => 'Admin ' . $subscription->commercial_name,
                'email' => 'admin@' . strtolower(str_replace([' ', ',', '.'], '', $subscription->commercial_name)) . '.com',
            ]);

            // 2. Crear Categorías y sus Atributos/Variantes específicos
            $ropaCategory = Category::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => 'Ropa y Accesorios',
            ]);
            // El atributo "Color" ahora requerirá una imagen
            $this->createAttributeWithOptions($ropaCategory, 'Color', ['Rojo', 'Azul', 'Negro', 'Blanco'], true);
            $this->createAttributeWithOptions($ropaCategory, 'Talla', ['S', 'M', 'L', 'XL']);

            $electronicaCategory = Category::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => 'Electrónica',
            ]);
            // Y aquí también
            $this->createAttributeWithOptions($electronicaCategory, 'Color', ['Negro Espacial', 'Plata', 'Oro'], true);
            $this->createAttributeWithOptions($electronicaCategory, 'Almacenamiento', ['128GB', '256GB', '512GB']);

            // Combinar todas las categorías para asignarlas a los productos
            $otherCategories = Category::factory(3)->create(['subscription_id' => $subscription->id]);
            $allCategories = collect([$ropaCategory, $electronicaCategory])->merge($otherCategories);

            // 3. Crear Marcas
            $brands = Brand::factory(5)->create(['subscription_id' => $subscription->id]);
            Provider::factory(3)->create(['subscription_id' => $subscription->id]);

            // 4. Crear 2 Sucursales por Suscriptor
            Branch::factory(2)->create(['subscription_id' => $subscription->id])->each(function ($branch, $index) use ($allCategories, $brands) {
                if ($index === 0) $branch->update(['is_main' => true]);

                // 5. Crear 10 Productos por Sucursal
                Product::factory(10)->create([
                    'branch_id' => $branch->id,
                    'category_id' => $allCategories->random()->id,
                    'brand_id' => $brands->random()->id,
                ]);
            });
        });
    }

    /**
     * Crea marcas globales y las asocia a tipos de negocio.
     */
    private function seedGlobalBrands(): void
    {
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

        // Marcas para Ropa
        $nike = Brand::factory()->create(['name' => 'Nike', 'subscription_id' => null]);
        $zara = Brand::factory()->create(['name' => 'Zara', 'subscription_id' => null]);

        // Marcas para Electrónica
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'subscription_id' => null]);
        $apple = Brand::factory()->create(['name' => 'Apple', 'subscription_id' => null]);

        // Asociar marcas a tipos de negocio
        $nike->businessTypes()->attach($ropaType->id);
        $zara->businessTypes()->attach($ropaType->id);
        $samsung->businessTypes()->attach($electronicaType->id);
        $apple->businessTypes()->attach($electronicaType->id);
    }

    /**
     * Helper para crear una definición de atributo con sus opciones.
     */
    private function createAttributeWithOptions(Category $category, string $attributeName, array $options, bool $requiresImage = false): void
    {
        $attributeDefinition = AttributeDefinition::factory()->create([
            'subscription_id' => $category->subscription_id,
            'category_id' => $category->id,
            'name' => $attributeName,
            'requires_image' => $requiresImage,
        ]);

        foreach ($options as $optionValue) {
            AttributeOption::factory()->create([
                'attribute_definition_id' => $attributeDefinition->id,
                'value' => $optionValue,
            ]);
        }
    }
}
