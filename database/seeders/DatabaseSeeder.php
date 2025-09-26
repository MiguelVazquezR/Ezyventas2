<?php

namespace Database\Seeders;

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Models\User;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomFieldDefinition;
use App\Models\PlanItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\AttributeDefinition;
use App\Models\AttributeOption;
use App\Models\BankAccount;
use App\Models\BusinessType;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GlobalProduct;
use App\Models\Payment;
use App\Models\Provider;
use App\Models\Quote;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\SessionCashMovement;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Llenar catálogos base
        $this->call([
            BusinessTypeSeeder::class,
            SettingDefinitionSeeder::class,
            PermissionSeeder::class,
            PlanItemSeeder::class, // Seeder del catálogo de planes
        ]);
        
        $this->seedGlobalBrandsAndProducts();

        // 2. Crear Suscriptores y sus datos privados
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

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

        // Se obtienen los items del plan desde el catálogo de la BD
        $planItems = PlanItem::where('is_active', true)->get();

        // --- Crear 2 versiones ANTERIORES para el historial ---
        for ($i = 2; $i >= 1; $i--) {
            $this->createSubscriptionVersion($subscription, now()->subYears($i), now()->subYears($i - 1), $planItems, true);
        }

        // --- Crear la versión ACTUAL ---
        $this->createSubscriptionVersion($subscription, now(), now()->addYear(), $planItems, false);
        
        // --- RESTO DE LA LÓGICA DE DATOS DE PRUEBA ---
        if ($businessType->name === 'Tienda de Electrónica') {
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'PIN de Desbloqueo', 'key' => 'pin_desbloqueo', 'type' => 'text']);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'Patrón de Desbloqueo', 'key' => 'patron_desbloqueo', 'type' => 'pattern']);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'Accesorios Incluidos', 'key' => 'accesorios_incluidos', 'type' => 'textarea']);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'Garantía Activa', 'key' => 'garantia_activa', 'type' => 'boolean', 'is_required' => true]);
        }

        if ($businessType->name === 'Tienda de Ropa y Accesorios') {
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'Tipo de Arreglo', 'key' => 'tipo_de_arreglo', 'type' => 'text', 'is_required' => true]);
            CustomFieldDefinition::factory()->create(['subscription_id' => $subscription->id, 'module' => 'service_orders', 'name' => 'Material de la Prenda', 'key' => 'material_prenda', 'type' => 'text']);
        }

        $ropaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Ropa y Accesorios', 'type' => 'product']);
        $this->createAttributeWithOptions($ropaCategory, 'Color', ['Rojo', 'Azul', 'Negro', 'Blanco'], true);
        $this->createAttributeWithOptions($ropaCategory, 'Talla', ['S', 'M', 'L', 'XL']);

        $electronicaCategory = Category::factory()->create(['subscription_id' => $subscription->id, 'name' => 'Electrónica', 'type' => 'product']);
        $this->createAttributeWithOptions($electronicaCategory, 'Color', ['Negro Espacial', 'Plata', 'Oro'], true);
        $this->createAttributeWithOptions($electronicaCategory, 'Almacenamiento', ['128GB', '256GB', '512GB']);

        $otherProductCategories = Category::factory(3)->create(['subscription_id' => $subscription->id, 'type' => 'product']);
        $allProductCategories = collect([$ropaCategory, $electronicaCategory])->merge($otherProductCategories);

        $brands = Brand::factory(5)->create(['subscription_id' => $subscription->id]);
        Provider::factory(3)->create(['subscription_id' => $subscription->id]);

        $branches = Branch::factory(2)->create(['subscription_id' => $subscription->id]);
        $mainBranch = $branches->first();
        $mainBranch->update(['is_main' => true]);

        $cashRegisters = CashRegister::factory(2)->create(['branch_id' => $mainBranch->id]);
        
        // Se crean cuentas bancarias y se asignan a las sucursales
        $bankAccounts = BankAccount::factory(2)->create(['subscription_id' => $subscription->id]);
        foreach($bankAccounts as $account) {
            $account->branches()->attach($branches->pluck('id'));
        }

        $serviceCategories = Category::factory(3)->create(['subscription_id' => $subscription->id, 'type' => 'service']);

        $adminUser = User::factory()->create([
            'branch_id' => $mainBranch->id,
            'name' => 'Admin ' . $subscription->commercial_name,
            'email' => 'admin@' . strtolower(str_replace([' ', ',', '.'], '', $subscription->commercial_name)) . '.com',
        ]);

        $branches->each(function ($branch) use ($serviceCategories, $adminUser, $allProductCategories, $brands, $cashRegisters) {
            $customers = Customer::factory(15)->create(['branch_id' => $branch->id]);

            CashRegisterSession::factory(5)->create([
                'cash_register_id' => $cashRegisters->random()->id,
                'user_id' => $adminUser->id,
            ])->each(function ($session) use ($branch, $adminUser, $customers) {
                $transactions = Transaction::factory(rand(10, 30))->create([
                    'branch_id' => $branch->id,
                    'user_id' => $adminUser->id,
                    'customer_id' => $customers->random()->id,
                    'cash_register_session_id' => $session->id,
                    'created_at' => $session->closed_at,
                ]);

                $transactions->where('status', 'completado')->each(function ($transaction) {
                    Payment::factory()->create([
                        'transaction_id' => $transaction->id,
                        'amount' => $transaction->subtotal - $transaction->total_discount,
                        'payment_date' => $transaction->created_at,
                    ]);
                });

                SessionCashMovement::factory(rand(1, 3))->create(['cash_register_session_id' => $session->id, 'type' => 'ingreso']);
                SessionCashMovement::factory(rand(1, 3))->create(['cash_register_session_id' => $session->id, 'type' => 'egreso']);

                $calculatedTotal = $session->opening_cash_balance + $transactions->sum(fn ($t) => $t->subtotal - $t->total_discount);
                $session->update([
                    'calculated_cash_total' => $calculatedTotal,
                    'closing_cash_balance' => $calculatedTotal + $session->cash_difference,
                ]);
            });

            Quote::factory(10)->create(['branch_id' => $branch->id, 'user_id' => $adminUser->id, 'customer_id' => $customers->random()->id]);
            Service::factory(15)->create(['branch_id' => $branch->id, 'category_id' => $serviceCategories->random()->id]);
            ServiceOrder::factory(20)->create(['branch_id' => $branch->id, 'user_id' => $adminUser->id]);
            Product::factory(10)->create(['branch_id' => $branch->id, 'category_id' => $allProductCategories->random()->id, 'brand_id' => $brands->random()->id]);
        });

        $expenseCategories = ExpenseCategory::factory(5)->create(['subscription_id' => $subscription->id]);
        Expense::factory(25)->create([
            'user_id' => $adminUser->id,
            'branch_id' => $branches->random()->id,
            'expense_category_id' => $expenseCategories->random()->id,
        ]);
    }

    /**
     * Crea una versión de suscripción con sus items y pago.
     */
    private function createSubscriptionVersion(Subscription $subscription, $startDate, $endDate, $planItems, bool $isPastVersion)
    {
        $version = SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $totalAmount = 0;
        $modules = $planItems->where('type', \App\Enums\PlanItemType::MODULE);
        $limits = $planItems->where('type', \App\Enums\PlanItemType::LIMIT);

        foreach ($modules as $module) {
            $annualPrice = $module->monthly_price * 10;
            SubscriptionItem::create([
                'subscription_version_id' => $version->id,
                'item_key' => $module->key,
                'item_type' => $module->type->value,
                'name' => $module->name,
                'quantity' => 1,
                'unit_price' => $annualPrice,
                'billing_period' => BillingPeriod::ANNUALLY,
            ]);
            $totalAmount += $annualPrice;
        }

        foreach ($limits as $limit) {
             SubscriptionItem::create([
                'subscription_version_id' => $version->id,
                'item_key' => $limit->key,
                'item_type' => $limit->type->value,
                'name' => $limit->name,
                'quantity' => $limit->meta['quantity'],
                'unit_price' => 0, // Los límites base se incluyen en el costo del plan
                'billing_period' => BillingPeriod::ANNUALLY,
            ]);
        }

        SubscriptionPayment::create([
            'subscription_version_id' => $version->id,
            'amount' => $totalAmount,
            'payment_method' => 'transferencia',
            'invoiced' => $isPastVersion,
            'invoice_status' => $isPastVersion ? InvoiceStatus::GENERATED : InvoiceStatus::NOT_REQUESTED,
            'created_at' => $startDate,
        ]);
    }

    private function seedGlobalBrandsAndProducts(): void
    {
        $ropaType = BusinessType::where('name', 'Tienda de Ropa y Accesorios')->first();
        $electronicaType = BusinessType::where('name', 'Tienda de Electrónica')->first();

        $nike = Brand::factory()->create(['name' => 'Nike', 'subscription_id' => null]);
        $zara = Brand::factory()->create(['name' => 'Zara', 'subscription_id' => null]);
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'subscription_id' => null]);
        $apple = Brand::factory()->create(['name' => 'Apple', 'subscription_id' => null]);

        $nike->businessTypes()->attach($ropaType->id);
        $zara->businessTypes()->attach($ropaType->id);
        $samsung->businessTypes()->attach($electronicaType->id);
        $apple->businessTypes()->attach($electronicaType->id);

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