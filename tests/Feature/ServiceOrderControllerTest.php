<?php

namespace Tests\Feature;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class ServiceOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private Product $product;
    private ProductAttribute $variant;
    private Service $service;
    private CashRegisterSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Configuración Base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;

        $subscription->update(['onboarding_completed_at' => now()]);

        // 2. Bypass de Middleware
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Permisos
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        $permissions = [
            'services.orders.access',
            'services.orders.create',
            'services.orders.see_details',
            'services.orders.edit',
            'services.orders.delete',
            'services.orders.change_status',
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'services']);
        }

        $role = Role::create(['name' => 'Técnico', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Datos de Prueba
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00
        ]);

        // Crear producto simple y vincularlo a pivote
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 100.00,
        ]);
        $this->product->branches()->attach($this->branch->id, ['current_stock' => 20, 'reserved_stock' => 0]);

        // Crear variante y vincularla a pivote
        $variantProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 200.00,
        ]);
        
        $this->variant = $variantProduct->productAttributes()->create([
            'attributes' => ['Size' => 'L'],
            'sku_suffix' => '-L',
            'selling_price_modifier' => 0
        ]);
        $this->variant->branches()->attach($this->branch->id, ['current_stock' => 10, 'reserved_stock' => 0]);

        $this->service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'base_price' => 500.00
        ]);

        $this->session = CashRegisterSession::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'abierta'
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_service_orders(): void
    {
        ServiceOrder::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id
        ]);

        $response = $this->get(route('service-orders.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('ServiceOrder/Index')
            ->has('serviceOrders.data', 3)
        );
    }

    #[Test]
    public function it_stores_service_order_creates_transaction_and_deducts_stock(): void
    {
        // Arrange
        $initialProductStock = 20;
        $initialVariantStock = 10;

        $payload = [
            'customer_id' => $this->customer->id,
            'create_customer' => false,
            'cash_register_session_id' => $this->session->id,
            'customer_name' => $this->customer->name,
            'status' => ServiceOrderStatus::PENDING->value,
            'received_at' => now()->toDateTimeString(),
            'item_description' => 'Laptop Dell Latitude',
            'reported_problems' => 'No enciende',
            
            'assign_technician' => true, 
            'technician_name' => 'Juan Pérez', 
            'technician_commission_type' => 'percentage',
            'technician_commission_value' => 0,
            
            'discount_type' => 'fixed', 
            'discount_value' => 0,
            
            'items' => [
                [
                    'itemable_type' => Product::class,
                    'itemable_id' => $this->product->id,
                    'description' => 'Batería Genérica',
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'line_total' => 100.00
                ],
                [
                    'itemable_type' => ProductAttribute::class,
                    'itemable_id' => $this->variant->id,
                    'description' => 'Memoria RAM',
                    'quantity' => 2,
                    'unit_price' => 200.00,
                    'line_total' => 400.00
                ],
                [
                    'itemable_type' => Service::class,
                    'itemable_id' => $this->service->id,
                    'description' => 'Diagnóstico y Reparación',
                    'quantity' => 1,
                    'unit_price' => 500.00,
                    'line_total' => 500.00
                ]
            ],
            'subtotal' => 1000.00,
            'discount_amount' => 0,
            'final_total' => 1000.00,
        ];

        // Act
        $response = $this->post(route('service-orders.store'), $payload);

        // Assert
        $response->assertSessionHasNoErrors();
        
        $this->assertDatabaseHas('service_orders', [
            'customer_id' => $this->customer->id,
            'item_description' => 'Laptop Dell Latitude',
            'final_total' => 1000.00,
            'status' => ServiceOrderStatus::PENDING->value
        ]);
        
        $order = ServiceOrder::latest()->first();
        $transaction = $order->transaction;

        // Verificar Transacción
        $this->assertNotNull($transaction, 'La transacción no se creó');
        $this->assertEquals(1000.00, $transaction->total);
        $this->assertEquals(TransactionStatus::PENDING, $transaction->status);

        // Verificar Deuda
        $this->assertEquals(-1000.00, $this->customer->fresh()->balance);

        // Verificar Stock en Pivot Tables
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals($initialProductStock - 1, $productPivot->current_stock, 'Stock de producto no descontado');
        
        $variantPivot = DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals($initialVariantStock - 2, $variantPivot->current_stock, 'Stock de variante no descontado');
    }

    #[Test]
    public function it_syncs_stock_correctly_when_updating_service_order_items(): void
    {
        // Arrange
        $order = ServiceOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'final_total' => 100.00
        ]);
        
        $order->items()->create([
            'itemable_type' => Product::class,
            'itemable_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100.00, 
            'line_total' => 100.00,
            'description' => 'Item Original'
        ]);
        
        $transaction = $order->transaction()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 100.00, 
            'total_discount' => 0,
            'total_tax' => 0,
            'status' => TransactionStatus::PENDING,
            'folio' => 'OS-V-TEST-001',
            'channel' => TransactionChannel::SERVICE_ORDER->value,
        ]);

        // Simular que el stock ya se había descontado en la creación original de la OS
        DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->decrement('current_stock', 1);
        $this->customer->decrement('balance', 100.00); 

        // Payload
        $payload = [
            'customer_name' => 'Cliente Actualizado',
            'item_description' => $order->item_description,
            'reported_problems' => $order->reported_problems,
            'status' => $order->status->value,
            'received_at' => $order->received_at->toDateTimeString(),
            
            'assign_technician' => true, 
            'technician_name' => 'Juan Pérez',
            'technician_commission_type' => 'fixed',
            'technician_commission_value' => 0,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            
            'items' => [
                [
                    'itemable_type' => ProductAttribute::class,
                    'itemable_id' => $this->variant->id,
                    'description' => 'Nuevo Item Variante',
                    'quantity' => 5,
                    'unit_price' => 200.00,
                    'line_total' => 1000.00
                ]
            ],
            'subtotal' => 1000.00,
            'discount_amount' => 0,
            'final_total' => 1000.00
        ];

        // Act
        $response = $this->put(route('service-orders.update', $order), $payload);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // 1. Stock Repuesto del item viejo (19 -> 20)
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(20, $productPivot->current_stock);

        // 2. Stock Descontado del item nuevo (10 -> 5)
        $variantPivot = DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(5, $variantPivot->current_stock);

        // 3. Saldos (-1000)
        $this->assertEquals(-1000.00, $this->customer->fresh()->balance);
        $this->assertEquals(1000.00, $transaction->fresh()->total);
    }

    #[Test]
    public function it_can_save_diagnosis_and_evidence_images(): void
    {
        Storage::fake('public');
        $order = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);
        $file = UploadedFile::fake()->image('evidencia_final.jpg');

        $payload = [
            'technician_diagnosis' => 'Reparación exitosa.',
            'closing_evidence_images' => [$file]
        ];

        $response = $this->post(route('service-orders.saveDiagnosis', $order), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals('Reparación exitosa.', $order->fresh()->technician_diagnosis);
        $this->assertCount(1, $order->getMedia('closing-service-order-evidence'));
    }

    #[Test]
    public function it_returns_stock_and_cancels_debt_when_status_changes_to_cancelled(): void
    {
        // Arrange
        $order = ServiceOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => ServiceOrderStatus::PENDING,
            'final_total' => 200.00
        ]);
        
        $order->items()->create([
            'itemable_type' => Product::class,
            'itemable_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 100.00,
            'line_total' => 200.00,
            'description' => 'Prod'
        ]);

        // Simular que el stock y la deuda ya ocurrieron (20 iniciales - 2 = 18)
        DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->update(['current_stock' => 18]);
        $this->customer->update(['balance' => -200.00]); 
        
        $transaction = $order->transaction()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 200.00,
            'total_discount' => 0,
            'total_tax' => 0,
            'status' => TransactionStatus::PENDING,
            'folio' => 'OS-V-TEST-002',
            'channel' => TransactionChannel::SERVICE_ORDER->value,
        ]);

        // Act
        $response = $this->patch(route('service-orders.updateStatus', $order), [
            'status' => ServiceOrderStatus::CANCELLED->value
        ]);

        // Assert
        $response->assertSessionHasNoErrors();
        
        // 1. Stock Devuelto a través del pivot (18 -> 20)
        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(20, $productPivot->current_stock);

        // 2. Deuda Anulada (-200 + 200 = 0)
        $this->assertEquals(0.00, $this->customer->fresh()->balance);

        // 3. Estatus
        $this->assertEquals(TransactionStatus::CANCELLED, $transaction->fresh()->status);
    }

    // --- NUEVAS PRUEBAS AÑADIDAS PARA COBERTURA AL 100% ---

    #[Test]
    public function it_renders_create_service_order_page(): void
    {
        $response = $this->get(route('service-orders.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ServiceOrder/Create')
                ->has('customers')
                ->has('products')
                ->has('services')
            );
    }

    #[Test]
    public function it_renders_edit_service_order_page(): void
    {
        $order = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('service-orders.edit', $order));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ServiceOrder/Edit')
                ->has('serviceOrder')
                ->has('customers')
                ->has('products')
            );
    }

    #[Test]
    public function it_shows_service_order_details(): void
    {
        $order = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('service-orders.show', $order));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ServiceOrder/Show')
                ->has('serviceOrder')
                ->has('activities')
                ->has('availableTemplates')
            );
    }

    #[Test]
    public function it_can_delete_a_service_order(): void
    {
        $order = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);
        // Las OS siempre tienen una transacción ligada, creémosla para testear que el controller la borra en cascada
        $order->transaction()->create([
            'branch_id' => $this->branch->id,
            'subtotal' => 100,
            'status' => TransactionStatus::PENDING,
            'folio' => 'DEL-001',
            'channel' => TransactionChannel::SERVICE_ORDER->value, // <-- CORRECCIÓN: Campo obligatorio añadido
        ]);

        $response = $this->delete(route('service-orders.destroy', $order));

        $response->assertRedirect(route('service-orders.index'));
        $this->assertDatabaseMissing('service_orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('transactions', ['folio' => 'DEL-001']);
    }

    #[Test]
    public function it_can_batch_delete_service_orders(): void
    {
        $order1 = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);
        $order2 = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);
        
        $ids = [$order1->id, $order2->id];

        $response = $this->post(route('service-orders.batchDestroy'), ['ids' => $ids]);

        $response->assertRedirect(route('service-orders.index'));
        
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('service_orders', ['id' => $id]);
        }
    }

    #[Test]
    public function it_denies_access_without_permissions(): void
    {
        // Quitar rol y permisos
        $this->user->roles()->detach();

        // Middleware intercepta
        $response = $this->get(route('service-orders.index'));
        $response->assertForbidden(); 
    }
}