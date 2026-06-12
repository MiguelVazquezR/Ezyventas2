<?php

namespace Tests\Feature;

use App\Enums\CustomerBalanceMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product; 
use App\Models\Transaction; 
use App\Enums\TransactionStatus;
use App\Enums\TransactionChannel;
use App\Models\SubscriptionVersion;
use App\Services\TransactionPaymentService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PointOfSaleControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private BankAccount $bankAccount;
    private CashRegisterSession $session;
    private Product $product;

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
        
        // 2. Marcar Onboarding como completado
        $subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // 3. Simular una versión de suscripción activa
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),  
            'end_date' => Carbon::tomorrow(),      
        ]);

        // 4. Configurar Permisos
        $permission = Permission::create([
            'name' => 'pos.create_sale', 
            'module' => 'pos'
        ]);
        $role = Role::create([
            'name' => 'Vendedor', 
            'branch_id' => $this->branch->id
        ]);
        $role->givePermissionTo($permission);
        $this->user->assignRole($role);

        // 5. Limpiar caché de Spatie
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        // 6. Crear cliente
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00
        ]);

        // 7. Crear cuenta bancaria
        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
            'balance' => 5000.00
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);

        // 8. Crear sesión de caja
        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id,
            'status' => 'abierta'
        ]);

        // 9. Crear producto y asignarle stock en la tabla PIVOTE
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 150.00,
            // Eliminado: 'current_stock'
        ]);
        $this->product->branches()->attach($this->branch->id, [
            'current_stock' => 20,
            'reserved_stock' => 0
        ]);

        // 10. Autenticar al usuario
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_creates_a_cash_sale_successfully(): void
    {
        // --- ARRANGE ---
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'product_attribute_id' => null,
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                    'discount_reason' => null
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'total' => 300.00, 
            'use_balance' => false,
            'payments' => [
                ['amount' => 100.00, 'method' => 'efectivo', 'bank_account_id' => null],
                ['amount' => 200.00, 'method' => 'tarjeta', 'bank_account_id' => $this->bankAccount->id]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('pos.index')); 

        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
        ]);

        $transaction = Transaction::where('customer_id', $this->customer->id)->first();
        
        $this->assertDatabaseHas('transactions_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->product->id,
            'quantity' => 2,
        ]);

        // Verificar pagos
        $this->assertDatabaseHas('payments', ['transaction_id' => $transaction->id, 'amount' => 100.00, 'payment_method' => 'efectivo']);
        $this->assertDatabaseHas('payments', ['transaction_id' => $transaction->id, 'amount' => 200.00, 'payment_method' => 'tarjeta']);

        // Verificar STOCK en la tabla PIVOTE
        $pivot = DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals(18, $pivot->current_stock, 'El stock del producto no se descontó correctamente en la pivote.');

        // Verificar SALDOS
        $this->assertEquals(5200.00, $this->bankAccount->fresh()->balance);
        $this->assertEquals(0.00, $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_creates_a_credit_sale_and_generates_customer_debt(): void
    {
        // --- ARRANGE ---
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'product_attribute_id' => null,
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                    'discount_reason' => null
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'total' => 300.00,
            'use_balance' => false,
            'payments' => [
                ['amount' => 100.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertRedirect(route('pos.index'));

        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::PENDING,
        ]);

        $transaction = Transaction::where('customer_id', $this->customer->id)->first();

        // Verificar STOCK descontado en la tabla pivote
        $pivot = DB::table('branch_product')->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->first();
        $this->assertEquals(18, $pivot->current_stock);

        // Verificar SALDO DEUDOR
        $this->assertEquals(-200.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE,
            'amount' => -200.00 
        ]);
    }

    #[Test]
    public function it_creates_a_sale_using_customer_balance(): void
    {
        // --- ARRANGE ---
        $this->customer->update(['balance' => 100.00]);

        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'product_attribute_id' => null,
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                    'discount_reason' => null
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'total' => 300.00,
            'use_balance' => true, 
            'payments' => [
                ['amount' => 200.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::COMPLETED, 
        ]);

        $transaction = Transaction::where('customer_id', $this->customer->id)->first();

        // Verificar STOCK en la tabla pivote
        $pivot = DB::table('branch_product')->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->first();
        $this->assertEquals(18, $pivot->current_stock);

        // Verificar SALDO A FAVOR CONSUMIDO
        $this->assertEquals(0.00, $this->customer->fresh()->balance);

        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_USAGE,
            'amount' => -100.00 
        ]);
    }

    #[Test]
    public function it_creates_a_layaway_sale_and_reserves_stock(): void
    {
        // --- ARRANGE ---
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'product_attribute_id' => null,
                    'quantity' => 2,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                    'discount_reason' => null
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'total' => 300.00,
            'use_balance' => false,
            'payments' => [
                ['amount' => 50.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ],
            'layaway_expiration_date' => Carbon::now()->addDays(30)->toDateString() 
        ];

        // --- ACT ---
        $response = $this->post(route('pos.layaway'), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::ON_LAYAWAY, 
        ]);

        $transaction = Transaction::where('customer_id', $this->customer->id)->first();

        // Verificar RESERVA DE STOCK en la tabla pivote
        $pivot = DB::table('branch_product')->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->first();
        $this->assertEquals(20, $pivot->current_stock, 'El stock actual (físico) no debió alterarse aún.');
        $this->assertEquals(2, $pivot->reserved_stock, 'El stock reservado no se incrementó.');

        // Verificar DEUDA DE APARTADO
        $this->assertEquals(-250.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::LAYAWAY_DEBT, 
            'amount' => -250.00 
        ]);
    }

    // --- NUEVAS PRUEBAS AÑADIDAS PARA COBERTURA AL 100% ---

    #[Test]
    public function it_validates_required_fields_when_creating_a_sale(): void
    {
        // Enviamos un payload vacío
        $response = $this->post(route('pos.checkout'), []);

        // El controlador debe rechazar y devolver los errores de validación de los campos requeridos
        $response->assertSessionHasErrors([
            'cash_register_session_id',
            'cartItems',
            'subtotal',
            'total',
            'use_balance'
        ]);
    }

    #[Test]
    public function it_handles_exceptions_thrown_by_the_payment_service_on_checkout(): void
    {
        // 1. Payload válido base
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'cartItems' => [
                [
                    'id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 150.00,
                    'description' => $this->product->name,
                    'discount' => 0,
                ]
            ],
            'customerId' => $this->customer->id,
            'subtotal' => 150.00,
            'total' => 150.00,
            'use_balance' => false,
            'payments' => []
        ];

        // 2. Mockear el Servicio para forzar excepción
        $this->mock(TransactionPaymentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('handleNewSale')
                ->once()
                ->andThrow(new Exception('Error simulado de inventario negativo'));
        });

        // 3. Ejecutar
        $response = $this->post(route('pos.checkout'), $payload);

        // 4. Assert de la redirección con mensaje atrapado en el catch
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Error al procesar la venta: Error simulado de inventario negativo');
    }
}