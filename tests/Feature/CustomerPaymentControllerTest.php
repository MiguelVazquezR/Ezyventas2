<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Transaction;
use App\Enums\TransactionStatus;
use App\Enums\CustomerBalanceMovementType;
use App\Enums\TransactionChannel;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test; // <-- Usar el atributo

class CustomerPaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Customer $customer;
    private BankAccount $bankAccount;
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

        $this->branch->subscription->update([
            'onboarding_completed_at' => now() 
        ]);
        
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0.00
        ]);

        // --- 2. CORRECCIÓN DEL ERROR branch_id ---
        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
            'balance' => 10000.00
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);
        // --- FIN DE LA CORRECCIÓN ---

        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id, // <-- Cambiado de 'opener_id'
            'status' => 'abierta'
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Crea una deuda (Transacción) para el cliente
     */
    private function createDebt(float $amount, string $date, TransactionStatus $status = TransactionStatus::PENDING): Transaction
    {
        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'subtotal' => $amount, // <-- CAMBIADO DE 'total' A 'subtotal'
            'total_discount' => 0, // <-- Añadido para claridad
            'total_tax' => 0,      // <-- Añadido para claridad
            'status' => $status,
            'created_at' => Carbon::parse($date),
            'updated_at' => Carbon::parse($date)
        ]);

        $this->customer->decrement('balance', $amount);
        $this->customer->balanceMovements()->create([
            'transaction_id' => $transaction->id,
            'type' => CustomerBalanceMovementType::CREDIT_SALE,
            'amount' => -$amount,
            'balance_after' => $this->customer->balance
        ]);
        
        return $transaction;
    }

    #[Test] // <-- Usar el atributo para el warning
    public function it_partially_applies_payment_to_oldest_debt(): void
    {
        // --- ARRANGE ---
        $debtOld = $this->createDebt(500.00, '2025-01-01');
        $debtNew = $this->createDebt(800.00, '2025-01-05');
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'notes' => 'Abono parcial',
            'payments' => [
                [
                    'amount' => 200.00,
                    'method' => 'efectivo',
                    'bank_account_id' => null,
                ]
            ]
        ];

        // --- ACT ---
        $response = $this->post(
            route('customers.payments.store', $this->customer), 
            $payload
        );

        // Detendrá la prueba y te dirá si un campo de validación falló.
        $response->assertSessionHasNoErrors();

        // --- ASSERT ---
        $response->assertRedirect();
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $debtOld->id,
            'amount' => 200.00
        ]);
        $this->assertEquals(TransactionStatus::PENDING, $debtOld->fresh()->status);
        $this->assertDatabaseMissing('payments', [
            'transaction_id' => $debtNew->id
        ]);
        $this->assertEquals(-1100.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $debtOld->id,
            'amount' => 200.00
        ]);
    }

    #[Test] // <-- Usar el atributo para el warning
    public function it_applies_payment_fifo_completes_debts_and_creates_positive_balance(): void
    {
        // --- ARRANGE ---
        $debtOld = $this->createDebt(500.00, '2025-01-01');
        $debtNew = $this->createDebt(800.00, '2025-01-05');
        $initialBankBalance = $this->bankAccount->balance;
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'notes' => 'Pago completo con excedente',
            'payments' => [
                [
                    'amount' => 1500.00,
                    'method' => 'transferencia',
                    'bank_account_id' => $this->bankAccount->id,
                ]
            ]
        ];

        // --- ACT ---
        $response = $this->post(route('customers.payments.store', $this->customer), $payload);

        // --- ASSERT ---
        $response->assertRedirect();
        $this->assertEquals(TransactionStatus::COMPLETED, $debtOld->fresh()->status);
        $this->assertEquals(TransactionStatus::COMPLETED, $debtNew->fresh()->status);
        $this->assertDatabaseHas('payments', ['transaction_id' => $debtOld->id, 'amount' => 500.00]);
        $this->assertDatabaseHas('payments', ['transaction_id' => $debtNew->id, 'amount' => 800.00]);
        $this->assertDatabaseHas('transactions', [
            'customer_id' => $this->customer->id,
            'channel' => TransactionChannel::BALANCE_PAYMENT->value,
            'subtotal' => 200.00,
            'folio' => 'ABONO-001'
        ]);
        
        $balanceTx = Transaction::where('folio', 'ABONO-001')->first();
        $this->assertDatabaseHas('payments', ['transaction_id' => $balanceTx->id, 'amount' => 200.00]);
        $this->assertEquals(200.00, $this->customer->fresh()->balance);
        
        // Comprobar los 3 movimientos de saldo
        $this->assertDatabaseHas('customer_balance_movements', ['type' => CustomerBalanceMovementType::PAYMENT, 'amount' => 500.00]);
        $this->assertDatabaseHas('customer_balance_movements', ['type' => CustomerBalanceMovementType::PAYMENT, 'amount' => 800.00]);
        $this->assertDatabaseHas('customer_balance_movements', ['type' => CustomerBalanceMovementType::PAYMENT, 'amount' => 200.00]);
        
        $expectedBankBalance = $initialBankBalance + 1500.00;
        $this->assertEquals($expectedBankBalance, $this->bankAccount->fresh()->balance);
    }
}