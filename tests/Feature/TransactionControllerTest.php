<?php

namespace Tests\Feature;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private Product $product;
    private ProductAttribute $variant;
    private CashRegisterSession $session;

    /**
     * Prepara el entorno para cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear datos base
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
            'transactions.access',
            'transactions.see_details',
            'transactions.cancel',
            'transactions.refund',
            'pos.create_sale' // Para la creación de pedidos
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'transactions']);
        }

        $role = Role::create(['name' => 'Cajero/Auditor', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Datos de Prueba y Stock (En Pivotes)
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00
        ]);

        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 100.00,
        ]);
        $this->product->branches()->attach($this->branch->id, ['current_stock' => 10, 'reserved_stock' => 0]);

        $variantProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 200.00,
        ]);
        
        $this->variant = $variantProduct->productAttributes()->create([
            'attributes' => ['Size' => 'M'],
            'sku_suffix' => '-M',
            'selling_price_modifier' => 0
        ]);
        $this->variant->branches()->attach($this->branch->id, ['current_stock' => 5, 'reserved_stock' => 0]);

        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'user_id' => $this->user->id,
            'cash_register_id' => $cashRegister->id,
            'status' => CashRegisterSessionStatus::OPEN->value
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_transactions_with_filters(): void
    {
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'folio' => 'V-100',
            'subtotal' => 500,
            'status' => TransactionStatus::COMPLETED->value
        ]);

        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'folio' => 'V-101',
            'subtotal' => 200,
            'status' => TransactionStatus::PENDING->value
        ]);

        $response = $this->get(route('transactions.index', ['search' => 'V-100']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Transaction/Index')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.folio', 'V-100')
        );
    }

    #[Test]
    public function it_shows_transaction_details(): void
    {
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->user->id,
            'status' => TransactionStatus::COMPLETED->value
        ]);

        $response = $this->get(route('transactions.show', $transaction));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transaction/Show')
                ->has('transaction')
            );
    }

    #[Test]
    public function it_cancels_a_completed_sale_and_returns_stock_to_pivots(): void
    {
        // Arrange
        DB::table('branch_product')->where('product_id', $this->product->id)->update(['current_stock' => 8]);
        DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->update(['current_stock' => 4]);

        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'status' => TransactionStatus::COMPLETED->value, 
            'channel' => TransactionChannel::POS->value,
            'subtotal' => 400,
            'total_discount' => 0, // <-- Forzar cero para evitar floats aleatorios del Faker
            'total_tax' => 0,
            'shipping_cost' => 0,
        ]);

        $transaction->items()->create([
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'description' => 'Producto Simple',
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200
        ]);
        
        $transaction->items()->create([
            'itemable_id' => $this->variant->id,
            'itemable_type' => ProductAttribute::class,
            'description' => 'Producto Variante',
            'quantity' => 1,
            'unit_price' => 200,
            'line_total' => 200
        ]);

        // Act
        $response = $this->post(route('transactions.cancel', $transaction), [
            'action' => 'refund', 
            'refund_method' => 'balance',
            'notes' => 'Cancelación de prueba'
        ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Modificado: Ahora esperamos que sea REFUNDED debido al 'action' => 'refund'
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);

        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(10, $productPivot->current_stock, 'Stock del producto no fue devuelto.');

        $variantPivot = DB::table('branch_product_attribute')->where('product_attribute_id', $this->variant->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(5, $variantPivot->current_stock, 'Stock de la variante no fue devuelto.');
    }

    #[Test]
    public function it_cancels_a_layaway_and_releases_reserved_stock_from_pivots(): void
    {
        // Arrange
        DB::table('branch_product')->where('product_id', $this->product->id)->update(['reserved_stock' => 2]);

        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'status' => TransactionStatus::ON_LAYAWAY->value, 
            'channel' => TransactionChannel::POS->value,
            'subtotal' => 200,
            'total_discount' => 0, // <-- Forzar cero para evitar floats aleatorios del Faker
            'total_tax' => 0,
            'shipping_cost' => 0,
        ]);

        $transaction->items()->create([
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'description' => 'Producto Apartado',
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200
        ]);

        // Act
        $response = $this->post(route('transactions.cancel', $transaction), [
            'action' => 'refund',
            'refund_method' => 'balance',
            'notes' => 'Cancelación de apartado'
        ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        
        // Modificado: Ahora esperamos que sea REFUNDED debido al 'action' => 'refund'
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);

        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(0, $productPivot->reserved_stock, 'El stock reservado no fue liberado.');
        $this->assertEquals(10, $productPivot->current_stock); 
    }

    #[Test]
    public function it_refunds_a_transaction_and_generates_balance_in_favor(): void
    {
        // Arrange
        $this->customer->update(['balance' => 0]);
        DB::table('branch_product')->where('product_id', $this->product->id)->update(['current_stock' => 8]); 

        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'status' => TransactionStatus::COMPLETED->value,
            'channel' => TransactionChannel::POS->value,
            'subtotal' => 200,
            'total_discount' => 0, // <-- Forzar cero para evitar que Faker reste montos aleatorios y afecte el reembolso final
            'total_tax' => 0,
            'shipping_cost' => 0,
        ]);

        $transaction->items()->create([
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'description' => 'Producto a reembolsar',
            'quantity' => 2,
            'unit_price' => 100,
            'line_total' => 200
        ]);

        $transaction->payments()->create([
            'amount' => 200.00,
            'payment_method' => 'efectivo',
            'status' => PaymentStatus::COMPLETED->value, // <-- Estado válido
            'payment_date' => now(), // <-- CORRECCIÓN: Campo obligatorio añadido
        ]);

        // Act
        $response = $this->post(route('transactions.refund', $transaction), [
            'action' => 'refund', 
            'refund_method' => 'balance',
            'notes' => 'Reembolso por garantía'
        ]);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);

        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(10, $productPivot->current_stock);

        $this->assertEquals(200.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::REFUND_CREDIT->value,
            'amount' => 200.00
        ]);
    }

    #[Test]
    public function it_creates_a_new_order_from_whatsapp_or_manual_channel(): void
    {
        // Arrange
        $payload = [
            'customerId' => $this->customer->id,
            'contact_info' => [
                'name' => 'Cliente WhatsApp',
                'phone' => '3312345678'
            ],
            'delivery_date' => now()->addDays(2)->format('Y-m-d'),
            'channel' => TransactionChannel::WHATSAPP->value,
            'subtotal' => 100.00,
            'shipping_cost' => 50.00,
            'total_discount' => 0,
            'total' => 150.00,
            'notes' => 'Entregar en portería',
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'description' => 'Producto Pedido WhatsApp',
                    'discount' => 0
                ]
            ],
            'payments' => [],
            'use_balance' => false,
            'cash_register_session_id' => $this->session->id,
        ];

        // Act
        $response = $this->postJson(route('pos.store-order'), $payload);

        // Assert
        $response->assertSessionHasNoErrors();
        
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'status' => TransactionStatus::TO_DELIVER->value, 
            'subtotal' => 100.00,
            'shipping_cost' => 50.00
        ]);

        $transaction = Transaction::latest()->first();

        $productPivot = DB::table('branch_product')->where('product_id', $this->product->id)->where('branch_id', $this->branch->id)->first();
        $this->assertEquals(10, $productPivot->current_stock);
        $this->assertEquals(1, $productPivot->reserved_stock);
    }

    #[Test]
    public function it_denies_access_without_permissions(): void
    {
        // Eliminar permisos
        $this->user->roles()->detach();

        // El middleware debe bloquear
        $response = $this->get(route('transactions.index'));
        
        $response->assertForbidden(); 
    }
}