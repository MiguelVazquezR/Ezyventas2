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
use PHPUnit\Framework\Attributes\Test; // <-- Usar el atributo

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;
    private BankAccount $bankAccount;
    private CashRegisterSession $session;
    private Transaction $transaction;
    private Branch $branch; // <-- Necesario para el subscription_id

    /**
     * Prepara el entorno para cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear datos base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => -1000.00
        ]);

        // --- 2. CORRECCIÓN DEL ERROR branch_id ---
        // Se crea la cuenta ligada a la SUBCRIPCIÓN
        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
            'balance' => 5000.00
        ]);

        // Y LUEGO se asigna al Branch a través de la tabla pivote
        $this->bankAccount->branches()->attach($this->branch->id);
        // --- FIN DE LA CORRECCIÓN ---

        $cashRegister = CashRegister::factory()->create(['branch_id' => $this->branch->id]);
        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $this->user->id, // <-- Cambiado de 'opener_id'
            'status' => 'abierta'
        ]);

        // 3. Crear la Orden de Servicio (Transacción)
        $this->transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'subtotal' => 1000.00, // <-- CAMBIADO DE 'total' A 'subtotal'
            'status' => TransactionStatus::PENDING
        ]);
    }

    #[Test] // <-- Usar el atributo para el warning
    public function it_correctly_applies_a_mixed_payment_to_service_order(): void
    {
        // --- ARRANGE ---
        $payload = [
            'cash_register_session_id' => $this->session->id,
            'use_balance' => false,
            'payments' => [
                [
                    'amount' => 200.00,
                    'method' => 'efectivo',
                    'bank_account_id' => null,
                ],
                [
                    'amount' => 300.00,
                    'method' => 'tarjeta',
                    'bank_account_id' => $this->bankAccount->id,
                ]
            ]
        ];

        // --- ACT ---
        $response = $this->actingAs($this->user)
            ->postJson(
                // Ajusta el nombre de tu ruta si es diferente
                route('payments.store', $this->transaction),
                $payload
            );

        // --- ASSERT ---
        $response->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'transaction_id' => $this->transaction->id,
            'amount' => 200.00
        ]);
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $this->transaction->id,
            'amount' => 300.00,
            'bank_account_id' => $this->bankAccount->id
        ]);
        $this->assertEquals(-500.00, $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'transaction_id' => $this->transaction->id,
            'amount' => 500.00
        ]);
        $this->assertEquals(5300.00, $this->bankAccount->fresh()->balance);
        $this->assertEquals(TransactionStatus::PENDING, $this->transaction->fresh()->status);
    }
}
