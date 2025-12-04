<?php

namespace Tests\Feature;

use App\Enums\CustomerBalanceMovementType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Configuración Base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;

        $subscription->update(['onboarding_completed_at' => now()]);

        // 2. Suscripción Activa (Bypass Middleware)
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Permisos
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        $permissions = [
            'customers.access',
            'customers.create',
            'customers.see_details',
            'customers.edit',
            'customers.delete',
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'customers']);
        }

        $role = Role::create(['name' => 'Admin', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Autenticar
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_customers_and_filter_by_name(): void
    {
        Customer::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Juan Perez']);
        Customer::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Maria Lopez']);

        // Prueba búsqueda
        $response = $this->get(route('customers.index', ['search' => 'Juan']));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.name', 'Juan Perez')
            );
    }

    #[Test]
    public function it_creates_a_customer_with_initial_balance_movement(): void
    {
        $payload = [
            'name' => 'Nuevo Cliente Deudor',
            'email' => 'cliente@test.com',
            'phone' => '555-0000',
            'initial_balance' => -500.00, // Empieza con deuda
            'credit_limit' => 1000.00,
        ];

        $response = $this->post(route('customers.store'), $payload);

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('success');

        // 1. Verificar Cliente creado
        $this->assertDatabaseHas('customers', [
            'name' => 'Nuevo Cliente Deudor',
            'balance' => -500.00,
            'branch_id' => $this->branch->id
        ]);

        $customer = Customer::where('email', 'cliente@test.com')->first();

        // 2. Verificar Movimiento de Saldo Inicial
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $customer->id,
            'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT->value,
            'amount' => -500.00,
            'balance_after' => -500.00,
            'notes' => 'Saldo Inicial registrado al crear cliente.'
        ]);
    }

    #[Test]
    public function it_can_update_customer_details(): void
    {
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Nombre Viejo'
        ]);

        $payload = [
            'name' => 'Nombre Actualizado',
            'email' => 'update@test.com',
            'phone' => '123456',
            'credit_limit' => 2000,
            // 'balance' y 'initial_balance' no deberían ser actualizables directamente por este método
        ];

        $response = $this->put(route('customers.update', $customer), $payload);

        $response->assertRedirect(route('customers.index'));
        
        $this->assertEquals('Nombre Actualizado', $customer->fresh()->name);
        $this->assertEquals(2000, $customer->fresh()->credit_limit);
    }

    #[Test]
    public function it_can_adjust_balance_using_add_method(): void
    {
        // Arrange: Cliente con saldo 100
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 100.00
        ]);

        // Act: Sumar -50 (un cargo o ajuste negativo)
        $payload = [
            'adjustment_type' => 'add',
            'amount' => -50.00,
            'notes' => 'Corrección de error'
        ];

        $response = $this->post(route('customers.adjustBalance', $customer), $payload);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 1. Saldo actualizado (100 - 50 = 50)
        $this->assertEquals(50.00, $customer->fresh()->balance);

        // 2. Movimiento registrado
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $customer->id,
            'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT->value,
            'amount' => -50.00,
            'balance_after' => 50.00,
            'notes' => 'Ajuste manual: Corrección de error'
        ]);
    }

    #[Test]
    public function it_can_adjust_balance_using_set_total_method(): void
    {
        // Arrange: Cliente con saldo 100
        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 100.00
        ]);

        // Act: Establecer saldo final en 500 (La diferencia es +400)
        $payload = [
            'adjustment_type' => 'set_total',
            'amount' => 500.00,
            'notes' => 'Reajuste total'
        ];

        $response = $this->post(route('customers.adjustBalance', $customer), $payload);

        // Assert
        $response->assertRedirect();

        // 1. Saldo actualizado a exactamente 500
        $this->assertEquals(500.00, $customer->fresh()->balance);

        // 2. Movimiento registrado por la diferencia (400)
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $customer->id,
            'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT->value,
            'amount' => 400.00, // 500 - 100
            'balance_after' => 500.00,
        ]);
    }

    #[Test]
    public function it_shows_customer_details_with_historical_movements(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        
        // Crear un movimiento ficticio para verificar que se carga
        $customer->balanceMovements()->create([
            'type' => CustomerBalanceMovementType::PAYMENT,
            'amount' => 100,
            'balance_after' => 100,
            'created_at' => now()
        ]);

        $response = $this->get(route('customers.show', $customer));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/Show')
                ->has('customer')
                ->has('historicalMovements', 1) // Verifica que se cargue el accessor
                ->has('availableCashRegisters')
                ->has('activeLayaways')
            );
    }

    #[Test]
    public function it_can_print_customer_statement(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('customers.printStatement', $customer));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customer/PrintStatement')
                ->has('customer')
                ->has('movements')
            );
    }

    #[Test]
    public function it_can_delete_a_customer(): void
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->delete(route('customers.destroy', $customer));

        $response->assertRedirect(route('customers.index'));
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }
}