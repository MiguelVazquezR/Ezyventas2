<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomFieldDefinition;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GlobalProduct;
use App\Models\Provider;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceOrder;
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
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

        // Crear una suscripción para cada tipo de negocio para asegurar datos de prueba consistentes
        $this->createSubscriptionData($ropaType);
        $this->createSubscriptionData($electronicaType);
    }

    /**
     * Crea una suscripción completa con todos sus datos asociados.
     */
    private function createSubscriptionData(BusinessType $businessType): void
    {
        $subscription = Subscription::factory()->create([
            'business_type_id' => $businessType->id,
        ]);

        // --- Crear Campos Personalizados para Órdenes de Servicio ---
        if ($businessType->name === 'Tienda de Electrónica') {
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'name' => 'PIN de Desbloqueo', 'key' => 'pin_desbloqueo', 'type' => 'text']);
            // CustomFieldDefinition::factory()->create([
            //     'subscription_id' => $subscription->id,
            //     'name' => 'Tipo de Falla',
            //     'key' => 'tipo_falla',
            //     'type' => 'select',
            //     'options' => ['Hardware', 'Software', 'Batería', 'Pantalla']
            // ]);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Patrón de Desbloqueo', 'key' => 'patron_desbloqueo', 'type' => 'pattern']);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Garantía Activa', 'key' => 'garantia_activa', 'type' => 'boolean', 'is_required' => true]);
        }

        if ($businessType->name === 'Tienda de Ropa y Accesorios') {
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Tipo de Arreglo', 'key' => 'tipo_de_arreglo', 'type' => 'text', 'is_required' => true]);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Material de la Prenda', 'key' => 'material_prenda', 'type' => 'text']);
        }

        // Crear Categorías de Productos y Atributos
        $ropaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Ropa y Accesorios', 'type' => 'product']);
        $this->createAttributeWithOptions($ropaCategory, 'Color', ['Rojo', 'Azul', 'Negro', 'Blanco'], true);
        $this->createAttributeWithOptions($ropaCategory, 'Talla', ['S', 'M', 'L', 'XL']);

        $electronicaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Electrónica', 'type' => 'product']);
        $this->createAttributeWithOptions($electronicaCategory, 'Color', ['Negro Espacial', 'Plata', 'Oro'], true);
        $this->createAttributeWithOptions($electronicaCategory, 'Almacenamiento', ['128GB', '256GB', '512GB']);

        $otherProductCategories = Category::factory(3)->create(['subscription_id' => $subscription->id, 'type' => 'product']);
        $allProductCategories = collect([$ropaCategory, $electronicaCategory])->merge($otherProductCategories);

        // Crear Marcas y Proveedores
        $brands = Brand::factory(5)->create(['subscription_id' => $subscription->id]);
        Provider::factory(3)->create(['subscription_id' => $subscription->id]);

        // Crear 2 Sucursales por Suscriptor
        $branches = Branch::factory(2)->create(['subscription_id' => $subscription->id]);
        $mainBranch = $branches->first();
        $mainBranch->update(['is_main' => true]);

        // Crear Categorías de Servicios
        $serviceCategories = Category::factory(3)->create(['subscription_id' => $subscription->id, 'type' => 'service']);

        // Crear 1 Usuario admin por Suscriptor y asignarlo a la sucursal principal
        $adminUser = User::factory()->create([
            'branch_id' => $mainBranch->id,
            'name' => 'Admin ' . $subscription->commercial_name,
            'email' => 'admin@' . strtolower(str_replace([' ', ',', '.'], '', $subscription->commercial_name)) . '.com',
        ]);

        // Crear datos por cada sucursal
        $branches->each(function ($branch) use ($serviceCategories, $adminUser, $allProductCategories, $brands) {
            $customers = Customer::factory(15)->create(['branch_id' => $branch->id]);
            Quote::factory(10)->create([
                'branch_id' => $branch->id,
                'user_id' => $adminUser->id,
                'customer_id' => $customers->random()->id,
            ]);
            Service::factory(15)->create(['branch_id' => $branch->id, 'category_id' => $serviceCategories->random()->id]);
            ServiceOrder::factory(20)->create(['branch_id' => $branch->id, 'user_id' => $adminUser->id]);
            Product::factory(10)->create(['branch_id' => $branch->id, 'category_id' => $allProductCategories->random()->id, 'brand_id' => $brands->random()->id]);
        });

        // Crear Categorías de Gastos y Gastos
        $expenseCategories = ExpenseCategory::factory(5)->create(['subscription_id' => $subscription->id]);
        Expense::factory(25)->create([
            'user_id' => $adminUser->id,
            'branch_id' => $branches->random()->id,
            'expense_category_id' => $expenseCategories->random()->id,
        ]);
    }

    private function seedGlobalBrandsAndProducts(): void
    {
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

        // Marcas globales
        $nike = Brand::factory()->create(['name' => 'Nike', 'subscription_id' => null]);
        $zara = Brand::factory()->create(['name' => 'Zara', 'subscription_id' => null]);
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'subscription_id' => null]);
        $apple = Brand::factory()->create(['name' => 'Apple', 'subscription_id' => null]);

        $nike->businessTypes()->attach($ropaType->id);
        $zara->businessTypes()->attach($ropaType->id);
        $samsung->businessTypes()->attach($electronicaType->id);
        $apple->businessTypes()->attach($electronicaType->id);

        // Productos Globales
        GlobalProduct::factory(20)->create(['brand_id' => $nike->id, 'business_type_id' => $ropaType->id]);
        GlobalProduct::factory(20)->create(['brand_id' => $samsung->id, 'business_type_id' => $electronicaType->id]);
    }

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
