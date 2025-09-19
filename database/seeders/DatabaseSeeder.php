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
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GlobalProduct;
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
        $this->seedGlobalBrandsAndProducts();

        // 2. Crear Suscriptores y sus datos privados
        $businessTypes = BusinessType::all();

        // Creamos 2 Suscripciones
        Subscription::factory(2)->create()->each(function ($subscription) use ($businessTypes) {
            // Asignar un tipo de negocio aleatorio a la suscripción
            $subscription->business_type_id = $businessTypes->random()->id;
            $subscription->save();

            // Crear Categorías y Atributos para la suscripción
            $ropaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Ropa y Accesorios']);
            $this->createAttributeWithOptions($ropaCategory, 'Color', ['Rojo', 'Azul', 'Negro', 'Blanco'], true);
            $this->createAttributeWithOptions($ropaCategory, 'Talla', ['S', 'M', 'L', 'XL']);

            $electronicaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Electrónica']);
            $this->createAttributeWithOptions($electronicaCategory, 'Color', ['Negro Espacial', 'Plata', 'Oro'], true);
            $this->createAttributeWithOptions($electronicaCategory, 'Almacenamiento', ['128GB', '256GB', '512GB']);
            
            $otherCategories = Category::factory(3)->create(['subscription_id' => $subscription->id]);
            $allCategories = collect([$ropaCategory, $electronicaCategory])->merge($otherCategories);

            // Crear Marcas y Proveedores para la suscripción
            $brands = Brand::factory(5)->create(['subscription_id' => $subscription->id]);
            Provider::factory(3)->create(['subscription_id' => $subscription->id]);

            // Crear 2 Sucursales por Suscriptor
            $branches = Branch::factory(2)->create(['subscription_id' => $subscription->id]);
            $mainBranch = $branches->first();
            $mainBranch->update(['is_main' => true]);

            // Crear 1 Usuario admin por Suscriptor y asignarlo a la sucursal principal
            $adminUser = User::factory()->create([
                'branch_id' => $mainBranch->id,
                'name' => 'Admin ' . $subscription->commercial_name,
                'email' => 'admin@' . strtolower(str_replace([' ', ',', '.'], '', $subscription->commercial_name)) . '.com',
            ]);

            // Crear Categorías de Gastos
            $expenseCategories = ExpenseCategory::factory(5)->create(['subscription_id' => $subscription->id]);
            
            // Crear 25 Gastos y asignarlos aleatoriamente a una de las sucursales
            Expense::factory(25)->create([
                'user_id' => $adminUser->id,
                'branch_id' => $branches->random()->id,
                'expense_category_id' => $expenseCategories->random()->id,
            ]);

            // Crear 10 Productos por cada Sucursal
            $branches->each(function ($branch) use ($allCategories, $brands) {
                Product::factory(10)->create([
                    'branch_id' => $branch->id,
                    'category_id' => $allCategories->random()->id,
                    'brand_id' => $brands->random()->id,
                ]);
            });
        });
    }

    /**
     * Crea marcas y productos globales y los asocia a tipos de negocio.
     */
    private function seedGlobalBrandsAndProducts(): void
    {
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

        // Marcas globales
        $nike = Brand::factory()->create(['name' => 'Nike', 'subscription_id' => null]);
        $zara = Brand::factory()->create(['name' => 'Zara', 'subscription_id' => null]);
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'subscription_id' => null]);
        $apple = Brand::factory()->create(['name' => 'Apple', 'subscription_id' => null]);

        // Asociar marcas a tipos de negocio (sigue siendo útil para organización interna)
        $nike->businessTypes()->attach($ropaType->id);
        $zara->businessTypes()->attach($ropaType->id);
        $samsung->businessTypes()->attach($electronicaType->id);
        $apple->businessTypes()->attach($electronicaType->id);

        // Crear Productos Globales
        GlobalProduct::factory(20)->create([
            'brand_id' => $nike->id,
            'business_type_id' => $ropaType->id,
        ]);
        GlobalProduct::factory(20)->create([
            'brand_id' => $samsung->id,
            'business_type_id' => $electronicaType->id,
        ]);
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