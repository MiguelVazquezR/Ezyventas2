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
            'transactions.exchange', 'transactions.add_payment' 
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
            'balance' => 0.00,
            'credit_limit' => 5000.00 // Damos crédito para pruebas
        ]);
        $this->product = Product::factory()->create([
            'branch_id' => $this->branch->id, 
            'current_stock' => 100,
            'selling_price' => 100.00
        ]);
        $this->variant = $this->product->productAttributes()->create([
            'attributes' => ['color' => 'rojo'],
            'current_stock' => 50,
            'selling_price_modifier' => 10.00 // Precio total 110
        ]);

        // 6. Crear sesión de caja
        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id, 
            'status' => CashRegisterSessionStatus::OPEN 
        ]);

        $this->session->users()->attach($this->user->id);

        // 7. Autenticar al usuario
        $this->actingAs($this->user);
    }

    /**
     * Función helper para crear un escenario de "Venta Generada".
     */
    private function createSaleFromQuote(float $customerBalance = -530.00): array
    {
        $this->product->update(['current_stock' => 98]);
        $this->variant->update(['current_stock' => 47]); 

        $this->customer->update(['balance' => $customerBalance]);

        $quote = Quote::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'status' => QuoteStatus::SALE_GENERATED,
        ]);
        
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'transactionable_id' => $quote->id,
            'transactionable_type' => Quote::class,
            'status' => $customerBalance < 0 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
            'subtotal' => 530, 'total_discount' => 0, 'total_tax' => 0,
        ]);
        
        $quote->update(['transaction_id' => $transaction->id]);

        $transaction->items()->create([
            'itemable_id' => $this->product->id, 'itemable_type' => Product::class,
            'description' => 'Producto Simple', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200
        ]);
        $transaction->items()->create([
            'itemable_id' => $this->variant->id, 'itemable_type' => ProductAttribute::class,
            'description' => 'Variante', 'quantity' => 3, 'unit_price' => 110, 'line_total' => 330
        ]);

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

        $initialProductStock = $this->product->fresh()->current_stock;
        $initialVariantStock = $this->variant->fresh()->current_stock;

        $payload = ['refund_method' => 'balance'];

        $response = $this->post(route('transactions.refund', $transaction), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);
        $this->assertEquals(530.00, $this->customer->fresh()->balance);
    }

    // --- CORRECCIÓN: PRUEBA DE CAMBIO ---

    #[Test]
    public function it_can_process_a_product_exchange_paying_difference(): void
    {
        // 1. Crear venta original
        ['transaction' => $originalTransaction] = $this->createSaleFromQuote(0.00); 
        $originalTransaction->update(['status' => TransactionStatus::COMPLETED]);
        
        // CRÍTICO: Registrar el pago de la venta original para que el sistema sepa que hay dinero para transferir
        Payment::factory()->create([
            'transaction_id' => $originalTransaction->id,
            'amount' => 530.00,
            'payment_method' => 'efectivo',
            'status' => 'completado'
        ]);
        
        // Devolvemos 1 unidad de la Variante (Precio original 110)
        $itemToReturn = $originalTransaction->items()
            ->where('itemable_type', ProductAttribute::class)
            ->first();

        // 2. Nuevo producto a llevar (Más caro: 200) -> Diferencia a pagar: 90
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
                    'description' => $newProduct->name, 
                    'discount' => 0,
                    'product_attribute_id' => null 
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
        $response->assertSessionHas('success');
        $response->assertRedirect();

        // Verificar estatus de la original
        $this->assertEquals(TransactionStatus::CHANGED, $originalTransaction->fresh()->status);

        // Verificar la nueva transacción
        $newTransaction = Transaction::latest('id')->first();
        $this->assertNotEquals($originalTransaction->id, $newTransaction->id);
        $this->assertEquals(200.00, $newTransaction->total);
        
        // Verificar Pagos: 110 transferidos (intercambio) + 90 efectivo
        $this->assertEquals(2, $newTransaction->payments()->count());
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

    // --- NUEVAS PRUEBAS ---

    #[Test]
    public function it_can_process_an_exchange_with_refund_to_balance(): void
    {
        // Venta original completada ($530)
        ['transaction' => $originalTransaction] = $this->createSaleFromQuote(0.00); 
        $originalTransaction->update(['status' => TransactionStatus::COMPLETED]);
        Payment::factory()->create(['transaction_id' => $originalTransaction->id, 'amount' => 530.00, 'status' => 'completado']);

        // Devolvemos 1 Variante ($110)
        $itemToReturn = $originalTransaction->items()->where('itemable_type', ProductAttribute::class)->first();

        // Llevamos algo más barato ($50) -> Sobran $60
        $cheapProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'selling_price' => 50.00, 'current_stock' => 10]);

        $payload = [
            'cash_register_session_id' => $this->session->id,
            'returned_items' => [['item_id' => $itemToReturn->id, 'quantity' => 1]],
            'new_items' => [[
                'id' => $cheapProduct->id, 'quantity' => 1, 'unit_price' => 50.00, 
                'description' => 'Barato', 'discount' => 0, 'product_attribute_id' => null
            ]],
            'subtotal' => 50.00,
            'total_discount' => 0,
            'payments' => [], // No hay pagos nuevos
            'exchange_refund_type' => 'balance', // Excedente a saldo
            'new_customer_id' => $this->customer->id
        ];

        $response = $this->post(route('transactions.exchange', $originalTransaction), $payload);

        $response->assertSessionHas('success');
        
        $newTransaction = Transaction::latest('id')->first();
        
        // Verificar que el pago cubrió la nueva venta ($50)
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $newTransaction->id,
            'amount' => 50.00,
            'payment_method' => 'intercambio'
        ]);

        // Verificar que el cliente recibió saldo ($60)
        $this->assertEquals(60.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'type' => CustomerBalanceMovementType::REFUND_CREDIT, // Reembolso a saldo
            'amount' => 60.00
        ]);
    }

    #[Test]
    public function it_can_process_an_exchange_using_credit_for_shortage(): void
    {
        // Venta original ($530) completada
        ['transaction' => $originalTransaction] = $this->createSaleFromQuote(0.00);
        $originalTransaction->update(['status' => TransactionStatus::COMPLETED]);
        Payment::factory()->create(['transaction_id' => $originalTransaction->id, 'amount' => 530.00, 'status' => 'completado']);

        // Devolvemos 1 Variante ($110)
        $itemToReturn = $originalTransaction->items()->where('itemable_type', ProductAttribute::class)->first();

        // Llevamos algo muy caro ($500) -> Diferencia $390. No pagamos nada, usamos crédito.
        $expensiveProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'selling_price' => 500.00, 'current_stock' => 10]);

        $payload = [
            'cash_register_session_id' => $this->session->id,
            'returned_items' => [['item_id' => $itemToReturn->id, 'quantity' => 1]],
            'new_items' => [[
                'id' => $expensiveProduct->id, 'quantity' => 1, 'unit_price' => 500.00, 
                'description' => 'Caro', 'discount' => 0, 'product_attribute_id' => null
            ]],
            'subtotal' => 500.00,
            'total_discount' => 0,
            'payments' => [],
            'use_credit_for_shortage' => true, // <-- Activamos crédito
            'new_customer_id' => $this->customer->id
        ];

        $response = $this->post(route('transactions.exchange', $originalTransaction), $payload);

        $response->assertSessionHas('success');

        $newTransaction = Transaction::latest('id')->first();
        
        // Debe quedar pendiente
        $this->assertEquals(TransactionStatus::PENDING, $newTransaction->status);
        
        // Verificar deuda en cliente (-$390)
        $this->assertEquals(-390.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'transaction_id' => $newTransaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE,
            'amount' => -390.00
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