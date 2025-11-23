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
use App\Models\Service;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

        // --- CORRECCIÓN CRÍTICA ---
        // Asignamos explícitamente el usuario a la sesión en la tabla pivote 'cash_register_session_user'.
        // Esto permite que $user->cashRegisterSessions() devuelva resultados en el controlador.
        $this->session->users()->attach($this->user->id);

        // 7. Autenticar al usuario
        $this->actingAs($this->user);
    }

    /**
     * Función helper para crear un escenario de "Venta Generada" desde una cotización.
     * Simula el estado *después* de que QuoteController::convertToSale() se ejecutó.
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

        // 6. Items (para devolución de stock)
        $transaction->items()->create([
            'itemable_id' => $this->product->id, 'itemable_type' => Product::class,
            'description' => 'Producto Simple', 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200
        ]);
        $transaction->items()->create([
            'itemable_id' => $this->variant->id, 'itemable_type' => ProductAttribute::class,
            'description' => 'Variante', 'quantity' => 3, 'unit_price' => 110, 'line_total' => 330
        ]);

        // 7. Movimiento de Saldo (si hay deuda)
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
        // --- ARRANGE ---
        $initialProductStock = 98;
        $initialVariantStock = 47;
        $initialBalance = -530.00;
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote($initialBalance);

        // --- ACT ---
        $response = $this->post(route('transactions.cancel', $transaction));

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Venta cancelada con éxito.');

        // 1. Estatus de Transacción
        $this->assertEquals(TransactionStatus::CANCELLED, $transaction->fresh()->status);
        
        // 2. Estatus de Cotización
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);

        // 3. Devolución de Stock
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock, 'Stock de producto simple no devuelto.');
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock, 'Stock de variante no devuelto.');

        // 4. Reversión de Saldo
        $this->assertEquals(0.00, $this->customer->fresh()->balance, 'El saldo del cliente no se restauró a 0.');
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CANCELLATION_CREDIT->value,
            'amount' => abs($initialBalance),
            'balance_after' => 0.00,
        ]);
    }

    #[Test]
    public function it_prevents_cancelling_a_transaction_with_payments(): void
    {
        // --- ARRANGE ---
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote();
        
        // Crear un pago
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100.00,
        ]);

        // --- ACT ---
        $response = $this->post(route('transactions.cancel', $transaction));

        // --- ASSERT ---
        $response->assertRedirect();
        $response->assertSessionHas('error', 'No se puede cancelar una venta con pagos registrados. Debe generar una devolución.');
        
        // Verificar que nada cambió
        $this->assertEquals(TransactionStatus::PENDING, $transaction->fresh()->status);
        $this->assertEquals(QuoteStatus::SALE_GENERATED, $quote->fresh()->status);
    }

    #[Test]
    public function it_can_refund_a_paid_quote_transaction_to_balance(): void
    {
        // --- ARRANGE ---
        // Simular una venta pagada (saldo cliente 0)
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote(0.00); 
        $transaction->update(['status' => TransactionStatus::COMPLETED]);
        
        // Crear el pago
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 530.00,
        ]);

        $initialProductStock = $this->product->fresh()->current_stock; // 98
        $initialVariantStock = $this->variant->fresh()->current_stock; // 47

        $payload = ['refund_method' => 'balance'];

        // --- ACT ---
        $response = $this->post(route('transactions.refund', $transaction), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Devolución generada con éxito.');

        // 1. Estatus
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);

        // 2. Devolución de Stock
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);

        // 3. Reversión de Saldo (A favor del cliente)
        $this->assertEquals(530.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::REFUND_CREDIT->value,
            'amount' => 530.00,
            'balance_after' => 530.00,
        ]);
    }

    #[Test]
    public function it_can_refund_a_paid_quote_transaction_to_cash(): void
    {
        // --- ARRANGE ---
        ['quote' => $quote, 'transaction' => $transaction] = $this->createSaleFromQuote(0.00);
        $transaction->update(['status' => TransactionStatus::COMPLETED]);
        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 530.00,
        ]);

        $initialProductStock = $this->product->fresh()->current_stock; // 98
        $initialVariantStock = $this->variant->fresh()->current_stock; // 47

        $payload = ['refund_method' => 'cash'];

        // --- ACT ---
        $response = $this->post(route('transactions.refund', $transaction), $payload);

        // --- ASSERT ---
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Devolución generada con éxito.');

        // 1. Estatus
        $this->assertEquals(TransactionStatus::REFUNDED, $transaction->fresh()->status);
        $this->assertEquals(QuoteStatus::CANCELLED, $quote->fresh()->status);

        // 2. Devolución de Stock
        $this->assertEquals($initialProductStock + 2, $this->product->fresh()->current_stock);
        $this->assertEquals($initialVariantStock + 3, $this->variant->fresh()->current_stock);

        // 3. Saldo de Cliente (NO debe cambiar)
        $this->assertEquals(0.00, $this->customer->fresh()->balance);
        $this->assertDatabaseMissing('customer_balance_movements', [
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::REFUND_CREDIT->value,
        ]);

        // 4. Movimiento de Caja (Salida de efectivo)
        $this->assertDatabaseHas('session_cash_movements', [
            'cash_register_session_id' => $this->session->id,
            'type' => SessionCashMovementType::OUTFLOW->value,
            'amount' => 530.00,
        ]);
    }
}