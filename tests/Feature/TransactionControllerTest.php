<?php

namespace Tests\Feature;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\QuoteStatus;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Quote;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

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
         $subscription->update([
            'onboarding_completed_at' => now()
        ]);

        // 2. Simular suscripción activa
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Configurar Permisos para Transacciones
        $permissions = [
            'transactions.access', 'transactions.see_details',
            'transactions.cancel', 'transactions.refund',
            'transactions.exchange', 'transactions.add_payment' // Agregamos los nuevos permisos
        ];
        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'module' => 'transactions']);
        }
        $role = Role::create(['name' => 'Cajero', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Limpiar caché de Spatie
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // 5. Crear datos de prueba
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00
        ]);
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id, 
            'current_stock' => 100
        ]);
        $this->variant = $this->product->productAttributes()->create([
            'attributes' => ['color' => 'rojo'],
            'current_stock' => 50,
        ]);

        // 6. Crear sesión de caja
        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id, // Usuario que la abrió (owner)
            'status' => CashRegisterSessionStatus::OPEN 
        ]);

        // Asignamos explícitamente el usuario a la sesión
        $this->session->users()->attach($this->user->id);

        // 7. Autenticar al usuario
        $this->actingAs($this->user);
    }

    /**
     * Función helper para crear un escenario de "Venta Generada" desde una cotización.
     */
    private function createSaleFromQuote(float $customerBalance = -530.00): array
    {
        // 1. Stocks iniciales (simulando que ya se descontaron)
        $this->product->update(['current_stock' => 98]); // 100 - 2
        $this->variant->update(['current_stock' => 47]); // 50 - 3

        // 2. Cliente con deuda
        $this->customer->update(['balance' => $customerBalance]);

        // 3. Crear Cotización
        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => QuoteStatus::SALE_GENERATED,
        ]);
        
        // 4. Crear Transacción
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'transactionable_id' => $quote->id,
            'transactionable_type' => Quote::class,
            'status' => $customerBalance < 0 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
            'subtotal' => 530, 'total_discount' => 0, 'total_tax' => 0,
        ]);
        
        // 5. Ligar cotización a transacción
        $quote->update(['transaction_id' => $transaction->id]);

        // 6. Items
        $transaction->items()->create([
            'itemable_id' => $this->product->id, 'itemable_type' => Product::class,
            'description' => 'Producto Simple', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200
        ]);
        $transaction->items()->create([
            'itemable_id' => $this->variant->id, 'itemable_type' => ProductAttribute::class,
            'description' => 'Variante', 'quantity' => 3, 'unit_price' => 110, 'line_total' => 330
        ]);

        // 7. Movimiento de Saldo
        if ($customerBalance < 0) {
            $this->customer->balanceMovements()->create([
                'transaction_id' => $transaction->id,
                'type' => CustomerBalanceMovementType::CREDIT_SALE,
                'amount' => $customerBalance,
                'balance_after' => $customerBalance,
            ]);
        }

        return compact('quote', 'transaction');
    }

    #[Test]
    public function it_can_cancel_a_pending_quote_transaction_and_returns_stock_and_balance(): void
    {
        $initialProductStock = 98;
        $initialVariantStock = 47;
        $initialBalance = -530.00;
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote($initialBalance);

        $response = $this->post(route('transactions.cancel', $transaction));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Venta cancelada con éxito.');

        $this->assertEquals(TransactionStatus::CANCELLED, $transaction->fresh()->status);
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);
        $this->assertEquals(0.00, $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_prevents_cancelling_a_transaction_with_payments(): void
    {
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote();
        
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
        ]);

        $response = $this->post(route('transactions.cancel', $transaction));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertEquals(TransactionStatus::PENDING, $transaction->fresh()->status);
    }

    #[Test]
    public function it_can_refund_a_paid_quote_transaction_to_balance(): void
    {
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote(0.00); 
        $transaction->update(['status' => TransactionStatus::COMPLETED]);
        
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 530.00,
        ]);

        $initialProductStock = $this->product->fresh()->current_stock; // 98
        $initialVariantStock = $this->variant->fresh()->current_stock; // 47

        $payload = ['refund_method' => 'balance'];

        $response = $this->post(route('transactions.refund', $transaction), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);
        $this->assertEquals(530.00, $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_can_refund_a_paid_quote_transaction_to_cash(): void
    {
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote(0.00);
        $transaction->update(['status' => TransactionStatus::COMPLETED]);
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 530.00,
        ]);

        $initialProductStock = $this->product->fresh()->current_stock; 
        $initialVariantStock = $this->variant->fresh()->current_stock; 

        $payload = ['refund_method' => 'cash'];

        $response = $this->post(route('transactions.refund', $transaction), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);
        $this->assertEquals(0.00, $this->customer->fresh()->balance);
    }

    // --- NUEVAS PRUEBAS PARA CAMBIOS Y ABONOS ---

    #[Test]
    public function it_can_process_a_product_exchange_paying_difference(): void
    {
        // 1. Crear venta original completada
        ['transaction' => $originalTransaction] = $this->createSaleFromQuote(0.00); 
        $originalTransaction->update(['status' => TransactionStatus::COMPLETED]);
        
        // Vamos a devolver 1 unidad de la Variante (Precio original 110)
        // Stock actual Variante: 47 (definido en createSaleFromQuote)
        $itemToReturn = $originalTransaction->items()
            ->where('itemable_type', ProductAttribute::class)
            ->first();

        // 2. Nuevo producto a llevar (Más caro: 200)
        $newProduct = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 200.00,
            'current_stock' => 10,
            'name' => 'Producto Nuevo'
        ]);

        $payload = [
            'cash_register_session_id' => $this->session->id,
            'returned_items' => [
                ['item_id' => $itemToReturn->id, 'quantity' => 1]
            ],
            'new_items' => [
                [
                    'id' => $newProduct->id,
                    'quantity' => 1,
                    'unit_price' => 200.00,
                    // --- AQUÍ ESTABA EL ERROR: Faltaban estos campos ---
                    'description' => $newProduct->name, 
                    'discount' => 0,
                    'product_attribute_id' => null 
                    // ---------------------------------------------------
                ]
            ],
            'subtotal' => 200.00,
            'total_discount' => 0,
            'payments' => [
                ['amount' => 90.00, 'method' => 'efectivo', 'notes' => 'Diferencia por cambio']
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('transactions.exchange', $originalTransaction), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 1. Verificar Stock:
        // Variante (Devuelta): 47 + 1 = 48
        $this->assertEquals(48, $this->variant->fresh()->current_stock, 'El stock devuelto no se incrementó.');
        // Nuevo Producto (Vendido): 10 - 1 = 9
        $this->assertEquals(9, $newProduct->fresh()->current_stock, 'El stock del nuevo producto no se descontó.');

        // 2. Verificar que se creó una nueva transacción
        $newTransaction = Transaction::latest('id')->first();
        $this->assertNotEquals($originalTransaction->id, $newTransaction->id);
        $this->assertEquals(TransactionStatus::COMPLETED, $newTransaction->status);
        $this->assertEquals(200.00, $newTransaction->total);

        // 3. Verificar los pagos
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $newTransaction->id,
            'amount' => 110.00,
            'payment_method' => 'intercambio'
        ]);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $newTransaction->id,
            'amount' => 90.00,
            'payment_method' => 'efectivo'
        ]);
    }

    #[Test]
    public function it_can_add_a_payment_to_an_pending_transaction(): void
    {
        // 1. Crear transacción pendiente con deuda (-530.00 saldo cliente)
        ['transaction' => $transaction] = $this->createSaleFromQuote(-530.00);
        $transaction->update(['status' => TransactionStatus::PENDING]);

        // Payload del abono (200.00)
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'payments' => [
                ['amount' => 200.00, 'method' => 'efectivo', 'notes' => 'Abono parcial']
            ],
            'use_balance' => false
        ];

        // --- ACT ---
        $response = $this->post(route('transactions.addPayment', $transaction), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Abono registrado con éxito.');

        // 1. Verificar que se creó el pago
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 200.00,
            'notes' => 'Abono parcial'
        ]);

        // 2. Verificar saldo del cliente
        // Saldo inicial: -530.00. Abono: +200.00. Nuevo saldo: -330.00
        $this->assertEquals(-330.00, $this->customer->fresh()->balance, 'El saldo del cliente no se actualizó correctamente.');

        // 3. Verificar movimiento de saldo
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::PAYMENT->value,
            'amount' => 200.00,
        ]);
    }
}