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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Creamos 2 Suscripciones
        Subscription::factory(2)->create()->each(function ($subscription) {
            
            // 1. Crear 1 Usuario admin por Suscriptor
            User::factory()->create([
                'subscription_id' => $subscription->id,
                'name' => 'Admin ' . $subscription->commercial_name,
                'email' => 'admin@' . strtolower(str_replace([' ', ',', '.'], '', $subscription->commercial_name)) . '.com',
            ]);

            // 2. Crear Categorías y sus Atributos/Variantes específicos
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