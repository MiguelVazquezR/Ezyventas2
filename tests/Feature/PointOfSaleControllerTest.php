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
use App\Models\Product; // <-- Importante
use App\Models\Transaction; // <-- Importante
use App\Enums\TransactionStatus;
use App\Enums\TransactionChannel;
use App\Models\SubscriptionVersion;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
        $subscription = $this->branch->subscription; // Obtenemos la suscripción
        
        // 2. Marcar Onboarding como completado
        $subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // --- 3. ¡AQUÍ ESTÁ LA CORRECCIÓN! ---
        // Simular una versión de suscripción activa para
        // pasar el middleware CheckSubscriptionStatus
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),  // Empezó ayer
            'end_date' => Carbon::tomorrow(),      // Termina mañana
            // No necesitamos 'status' u otros campos según tu modelo
        ]);
        // --- FIN DE LA CORRECCIÓN ---

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

        // 9. Crear producto
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 150.00,
            'current_stock' => 20
        ]);

        // 10. Autenticar al usuario
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_creates_a_cash_sale_successfully(): void
    {
        // --- ARRANGE ---
        // Simular un carrito que compra 2 unidades del producto
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
            'subtotal' => 300.00, // 2 * 150.00
            'total_discount' => 0,
            'total' => 300.00, // (subtotal - discount) + tax
            'use_balance' => false,
            'payments' => [
                // Pagar con $100 en efectivo y $200 en tarjeta
                ['amount' => 100.00, 'method' => 'efectivo', 'bank_account_id' => null],
                ['amount' => 200.00, 'method' => 'tarjeta', 'bank_account_id' => $this->bankAccount->id]
            ]
        ];

        // --- ACT ---
        // Asumo que tu ruta se llama 'pos.checkout'
        // Si es diferente, ajústala.
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---
        
        // 1. Verificar respuesta exitosa
        $response->assertSessionHasNoErrors();
        // Asumo que redirige al index del POS
        $response->assertRedirect(route('pos.index')); 

        // 2. Verificar que se creó la Transacción
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 300.00,
            'total_discount' => 0,
            'status' => TransactionStatus::COMPLETED, // ¡Importante!
            'channel' => TransactionChannel::POS,
            'folio' => 'V-001' // Primera venta
        ]);

        // 3. Verificar que se guardó el Item de la transacción
        $transaction = Transaction::where('folio', 'V-001')->first();
        $this->assertDatabaseHas('transactions_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->product->id,
            'itemable_type' => Product::class,
            'quantity' => 2,
            'unit_price' => 150.00
        ]);

        // 4. Verificar que se guardaron los Pagos
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'payment_method' => 'efectivo'
        ]);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'payment_method' => 'tarjeta',
            'bank_account_id' => $this->bankAccount->id
        ]);

        // 5. Verificar que el STOCK se descontó
        $this->assertEquals(
            18, // 20 iniciales - 2 vendidos
            $this->product->fresh()->current_stock,
            'El stock del producto no se descontó.'
        );

        // 6. Verificar que el SALDO BANCARIO aumentó
        $this->assertEquals(
            5200.00, // 5000 iniciales + 200 del pago con tarjeta
            $this->bankAccount->fresh()->balance,
            'El saldo de la cuenta bancaria no aumentó.'
        );

        // 7. Verificar que el SALDO DEL CLIENTE no cambió
        $this->assertEquals(
            0.00,
            $this->customer->fresh()->balance,
            'El saldo del cliente no debió cambiar.'
        );
        $this->assertDatabaseMissing('customer_balance_movements', [
            'customer_id' => $this->customer->id
        ]);
    }

    #[Test]
    public function it_creates_a_credit_sale_and_generates_customer_debt(): void
    {
        // --- ARRANGE ---
        // El cliente empieza con saldo 0
        $this->customer->update(['balance' => 0.00]);
        // El producto tiene 20 en stock
        $initialStock = $this->product->current_stock; // 20

        // El carrito es de $300 (2 productos de $150)
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
                // Pero solo se paga $100 en efectivo
                ['amount' => 100.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---

        // 1. Verificar respuesta exitosa
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertRedirect(route('pos.index'));

        // 2. Verificar que se creó la Transacción, pero como PENDIENTE
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::PENDING, // <-- ¡Importante!
            'folio' => 'V-001'
        ]);

        $transaction = Transaction::where('folio', 'V-001')->first();

        // 3. Verificar que se guardó el pago parcial de $100
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'payment_method' => 'efectivo'
        ]);

        // 4. Verificar que el STOCK se descontó (la venta se concretó)
        $this->assertEquals(
            $initialStock - 2, // 20 - 2 = 18
            $this->product->fresh()->current_stock,
            'El stock del producto no se descontó.'
        );

        // 5. Verificar que el SALDO DEL CLIENTE ahora es negativo (debe $200)
        $this->assertEquals(
            -200.00, // $100 pagados - $300 de la venta
            $this->customer->fresh()->balance,
            'El saldo del cliente no es la deuda correcta.'
        );

        // 6. Verificar que se creó el Movimiento de Saldo por la DEUDA
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE,
            'amount' => -200.00 // ¡Importante! El movimiento es por la deuda
        ]);
    }

    #[Test]
    public function it_creates_a_sale_using_customer_balance(): void
    {
        // --- ARRANGE ---
        // El cliente tiene $100 de saldo a favor
        $this->customer->update(['balance' => 100.00]);
        // El producto tiene 20 en stock
        $initialStock = $this->product->current_stock; // 20

        // El carrito es de $300 (2 productos de $150)
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
            'use_balance' => true, // <-- ¡Importante!
            'payments' => [
                // El cliente paga los $200 restantes en efectivo
                ['amount' => 200.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('pos.checkout'), $payload);

        // --- ASSERT ---

        // 1. Verificar respuesta exitosa
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertRedirect(route('pos.index'));

        // 2. Verificar que la Transacción se creó como COMPLETADA
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::COMPLETED, // <-- ¡Importante!
            'folio' => 'V-001'
        ]);

        $transaction = Transaction::where('folio', 'V-001')->first();

        // 3. Verificar que se guardaron AMBOS pagos
        // Pago 1: El pago con Saldo
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
            'payment_method' => 'saldo'
        ]);
        // Pago 2: El pago en efectivo
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'payment_method' => 'efectivo'
        ]);

        // 4. Verificar que el STOCK se descontó
        $this->assertEquals(
            $initialStock - 2, // 20 - 2 = 18
            $this->product->fresh()->current_stock,
            'El stock del producto no se descontó.'
        );

        // 5. Verificar que el SALDO DEL CLIENTE se consumió (100 - 100 = 0)
        $this->assertEquals(
            0.00,
            $this->customer->fresh()->balance,
            'El saldo a favor del cliente no se utilizó.'
        );

        // 6. Verificar que se creó el Movimiento de Saldo por el USO del saldo
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_USAGE,
            'amount' => -100.00 // ¡Importante! Es un movimiento negativo (un egreso)
        ]);
    }

    #[Test]
    public function it_creates_a_layaway_sale_and_reserves_stock(): void
    {
        // --- ARRANGE ---
        // Cliente empieza con saldo 0
        $this->customer->update(['balance' => 0.00]);
        // Producto empieza con 20 en stock y 0 reservado
        $this->product->update(['current_stock' => 20, 'reserved_stock' => 0]);
        $initialStock = $this->product->current_stock;
        $initialReservedStock = $this->product->reserved_stock;

        // Venta de $300 (2 productos de $150)
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
                // Se da un anticipo de $50 en efectivo
                ['amount' => 50.00, 'method' => 'efectivo', 'bank_account_id' => null]
            ]
        ];

        // --- ACT ---
        // Se llama a la ruta de 'layaway'
        $response = $this->post(route('pos.layaway'), $payload);

        // --- ASSERT ---

        // 1. Verificar respuesta exitosa
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertRedirect(route('pos.index'));

        // 2. Verificar que la Transacción se creó como ON_LAYAWAY
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'subtotal' => 300.00,
            'status' => TransactionStatus::ON_LAYAWAY, // <-- ¡Importante!
            'folio' => 'V-001'
        ]);

        $transaction = Transaction::where('folio', 'V-001')->first();

        // 3. Verificar que se guardó el anticipo de $50
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 50.00,
            'payment_method' => 'efectivo'
        ]);

        // 4. Verificar que el SALDO DEL CLIENTE ahora es negativo (debe $250)
        $this->assertEquals(
            -250.00, // $50 pagados - $300 de la venta
            $this->customer->fresh()->balance,
            'El saldo del cliente no es la deuda correcta.'
        );

        // 5. Verificar que se creó el Movimiento de Saldo por la DEUDA
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::LAYAWAY_DEBT, // <-- ¡Importante!
            'amount' => -250.00 
        ]);

        // 6. Verificar que el STOCK FÍSICO (current_stock) NO cambió
        $this->assertEquals(
            $initialStock, // Sigue siendo 20
            $this->product->fresh()->current_stock,
            'El stock físico (current_stock) no debió cambiar.'
        );

        // 7. Verificar que el STOCK RESERVADO SÍ aumentó
        $this->assertEquals(
            $initialReservedStock + 2, // 0 + 2 = 2
            $this->product->fresh()->reserved_stock,
            'El stock reservado (reserved_stock) no aumentó.'
        );
    }
}