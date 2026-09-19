<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SessionCashMovementType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the closing flow of the mobile API phase 4: leaving a shift, retaking
 * it, reading the cut and closing the register.
 */
class CashRegisterCloseApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private User $owner;

    private string $token;

    private CashRegister $cashRegister;

    private CashRegisterSession $session;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->token = $this->tokenFor($this->owner);

        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'BBVA',
            'account_name' => 'Cuenta principal',
            'balance' => 5000,
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);

        $this->cashRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 1',
            'is_active' => true,
            'in_use' => true,
        ]);

        $this->session = CashRegisterSession::factory()->create([
            'cash_register_id' => $this->cashRegister->id,
            'user_id' => $this->owner->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subHours(4),
            'closed_at' => null,
            'opening_cash_balance' => 1500,
            'opening_bank_balances' => [
                [
                    'id' => $this->bankAccount->id,
                    'account_name' => 'Cuenta principal',
                    'bank_name' => 'BBVA',
                    'balance' => 5000,
                ],
            ],
        ]);
        $this->session->users()->attach($this->owner->id);
    }

    /**
     * Sale booked in the shift with a completed payment.
     */
    private function payment(PaymentMethod $method, float $amount, ?int $bankAccountId = null): Payment
    {
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'cash_register_session_id' => $this->session->id,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => $amount,
            'created_at' => now()->subHour(),
        ]);

        return Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'cash_register_session_id' => $this->session->id,
            'amount' => $amount,
            'payment_method' => $method,
            'status' => PaymentStatus::COMPLETED,
            'bank_account_id' => $bankAccountId,
        ]);
    }

    /**
     * Fills the shift so the cut has real numbers: 5050 expected in the drawer.
     */
    private function fillShift(): void
    {
        $this->payment(PaymentMethod::CASH, 3500);
        $this->payment(PaymentMethod::CARD, 1200, $this->bankAccount->id);
        $this->payment(PaymentMethod::TRANSFER, 300, $this->bankAccount->id);

        $this->session->cashMovements()->create([
            'user_id' => $this->owner->id,
            'type' => SessionCashMovementType::INFLOW,
            'amount' => 200,
            'description' => 'Ingreso de efectivo',
        ]);
        $this->session->cashMovements()->create([
            'user_id' => $this->owner->id,
            'type' => SessionCashMovementType::OUTFLOW,
            'amount' => 150,
            'description' => 'Compra de bolsas',
        ]);
    }

    #[Test]
    public function it_returns_the_cut_summary_before_closing(): void
    {
        $this->fillShift();

        $response = $this->withToken($this->token)
            ->getJson('/api/v1/cash-register-sessions/' . $this->session->id . '/summary');

        // 1500 opening + 3500 cash sales + 200 inflow - 150 outflow.
        $response->assertOk()
            ->assertJsonPath('session.id', $this->session->id)
            ->assertJsonPath('session.status', 'abierta')
            ->assertJsonPath('session.cash_register.name', 'Caja 1')
            ->assertJsonPath('session.opener.id', $this->owner->id)
            ->assertJsonPath('session.closing_cash_balance', null)
            ->assertJsonPath('cash.opening', 1500)
            ->assertJsonPath('cash.cash_sales', 3500)
            ->assertJsonPath('cash.inflows', 200)
            ->assertJsonPath('cash.outflows', 150)
            ->assertJsonPath('cash.expected_total', 5050)
            ->assertJsonPath('cash.counted_total', null)
            ->assertJsonPath('cash.difference', null)
            ->assertJsonPath('payments_by_method.efectivo', 3500)
            ->assertJsonPath('payments_by_method.tarjeta', 1200)
            ->assertJsonPath('payments_by_method.transferencia', 300)
            ->assertJsonPath('payments_by_method.saldo', 0)
            ->assertJsonCount(2, 'cash_movements')
            ->assertJsonPath('cash_movements.0.type', 'ingreso')
            ->assertJsonPath('cash_movements.0.user.id', $this->owner->id)
            ->assertJsonCount(1, 'bank_accounts')
            ->assertJsonPath('bank_accounts.0.initial_balance', 5000)
            ->assertJsonPath('bank_accounts.0.received', 1500)
            ->assertJsonPath('bank_accounts.0.final_balance', 6500)
            ->assertJsonPath('counts.transactions', 3)
            ->assertJsonPath('counts.payments', 3);
    }

    #[Test]
    public function it_closes_the_register_and_reconciles_the_bank_accounts(): void
    {
        $this->fillShift();

        $response = $this->withToken($this->token)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, [
                'closing_cash_balance' => 5040,
                'notes' => 'Faltante por un cambio mal dado.',
            ]);

        // 5040 counted against 5050 expected: 10 pesos missing.
        $response->assertOk()
            ->assertJsonPath('message', 'Corte de caja realizado con éxito.')
            ->assertJsonPath('session.status', 'cerrada')
            ->assertJsonPath('session.calculated_cash_total', 5050)
            ->assertJsonPath('session.closing_cash_balance', 5040)
            ->assertJsonPath('session.cash_difference', -10)
            ->assertJsonPath('summary.session.status', 'cerrada')
            ->assertJsonPath('summary.session.cash_difference', -10)
            ->assertJsonPath('summary.cash.counted_total', 5040)
            ->assertJsonPath('summary.cash.difference', -10)
            ->assertJsonPath('summary.bank_accounts.0.final_balance', 6500);

        $session = $this->session->fresh();
        $this->assertEquals(CashRegisterSessionStatus::CLOSED, $session->status);
        $this->assertNotNull($session->closed_at);
        $this->assertEquals('Faltante por un cambio mal dado.', $session->notes);
        // The closing snapshot is frozen and the account keeps the final balance.
        $this->assertEquals(6500, (float) $session->closing_bank_balances[0]['balance']);
        $this->assertEquals(6500, (float) $this->bankAccount->fresh()->balance);
        // The terminal is free again.
        $this->assertFalse((bool) $this->cashRegister->fresh()->in_use);
    }

    #[Test]
    public function it_validates_the_closing_amount(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El monto de cierre es obligatorio.');

        $this->withToken($this->token)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, ['closing_cash_balance' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'closing_cash_balance' => 'El monto de cierre no puede ser negativo.',
            ]);

        $this->assertEquals(CashRegisterSessionStatus::OPEN, $this->session->fresh()->status);
    }

    #[Test]
    public function it_rejects_closing_a_session_the_user_does_not_take_part_in(): void
    {
        $stranger = $this->ownerUser();
        $strangerToken = $this->tokenFor($stranger);

        $this->withToken($strangerToken)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, ['closing_cash_balance' => 1500])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_session_participant')
            ->assertJsonPath('message', 'No participas en esta sesión de caja.');

        $this->assertEquals(CashRegisterSessionStatus::OPEN, $this->session->fresh()->status);
    }

    #[Test]
    public function it_rejects_closing_an_already_closed_session(): void
    {
        $this->session->update([
            'status' => CashRegisterSessionStatus::CLOSED,
            'closed_at' => now()->subMinutes(30),
        ]);

        $this->withToken($this->token)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, ['closing_cash_balance' => 1500])
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_not_open')
            ->assertJsonPath('message', 'Esa sesión de caja ya fue cerrada.');
    }

    #[Test]
    public function it_leaves_the_session_without_closing_it(): void
    {
        $partner = $this->employeeUser(['pos.access']);
        $this->session->users()->attach($partner->id);

        $this->withToken($this->tokenFor($partner))
            ->postJson('/api/v1/cash-register-sessions/' . $this->session->id . '/leave')
            ->assertOk()
            ->assertJsonPath('message', 'Has salido de la sesión de caja.');

        $this->assertDatabaseMissing('cash_register_session_user', [
            'cash_register_session_id' => $this->session->id,
            'user_id' => $partner->id,
        ]);
        // The shift keeps running for the rest of the team.
        $this->assertDatabaseHas('cash_register_session_user', [
            'cash_register_session_id' => $this->session->id,
            'user_id' => $this->owner->id,
        ]);
        $this->assertEquals(CashRegisterSessionStatus::OPEN, $this->session->fresh()->status);
        $this->assertTrue((bool) $this->cashRegister->fresh()->in_use);
    }

    #[Test]
    public function it_rejoins_the_session_that_is_open_on_the_terminal(): void
    {
        $partner = $this->employeeUser(['pos.access']);

        $this->withToken($this->tokenFor($partner))
            ->postJson('/api/v1/cash-register-sessions/rejoin-or-start', [
                'cash_register_id' => $this->cashRegister->id,
                'original_opener_id' => $this->owner->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Te has unido a la nueva sesión.')
            ->assertJsonPath('active_session.id', $this->session->id);

        $this->assertDatabaseHas('cash_register_session_user', [
            'cash_register_session_id' => $this->session->id,
            'user_id' => $partner->id,
        ]);
        $this->assertEquals(1, CashRegisterSession::count());
    }

    #[Test]
    public function it_starts_a_new_session_inheriting_the_last_cut(): void
    {
        // The previous shift of this terminal was already cut.
        $this->session->update([
            'status' => CashRegisterSessionStatus::CLOSED,
            'closed_at' => now()->subHour(),
            'closing_cash_balance' => 5040,
            'closing_bank_balances' => [
                [
                    'id' => $this->bankAccount->id,
                    'account_name' => 'Cuenta principal',
                    'bank_name' => 'BBVA',
                    'balance' => 6500,
                ],
            ],
        ]);
        $this->cashRegister->update(['in_use' => false]);

        $worker = $this->employeeUser(['pos.access']);
        $workerToken = $this->tokenFor($worker);

        $response = $this->withToken($workerToken)
            ->postJson('/api/v1/cash-register-sessions/rejoin-or-start', [
                'cash_register_id' => $this->cashRegister->id,
                'original_opener_id' => $this->owner->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Te has unido a la nueva sesión.')
            ->assertJsonPath('active_session.opening_cash_balance', 5040)
            ->assertJsonPath('active_session.opener.id', $this->owner->id)
            ->assertJsonPath('active_session.cash_register.id', $this->cashRegister->id);

        $newSession = CashRegisterSession::latest('id')->first();
        $this->assertNotEquals($this->session->id, $newSession->id);
        // The fund and the bank balances continue where the last cut left them.
        $this->assertEquals(6500, (float) $newSession->opening_bank_balances[0]['balance']);
        $this->assertDatabaseHas('cash_register_session_user', [
            'cash_register_session_id' => $newSession->id,
            'user_id' => $worker->id,
        ]);
        $this->assertTrue((bool) $this->cashRegister->fresh()->in_use);
    }

    #[Test]
    public function it_rejects_rejoining_when_the_user_already_has_a_shift(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/cash-register-sessions/rejoin-or-start', [
                'cash_register_id' => $this->cashRegister->id,
                'original_opener_id' => $this->owner->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_already_open')
            ->assertJsonPath('message', 'Ya tienes una sesión activa.');
    }

    #[Test]
    public function it_requires_the_pos_permission_for_the_cut(): void
    {
        $employee = $this->employeeUser(['customers.access']);
        $token = $this->tokenFor($employee);
        $this->session->users()->attach($employee->id);

        $this->withToken($token)
            ->getJson('/api/v1/cash-register-sessions/' . $this->session->id . '/summary')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->putJson('/api/v1/cash-register-sessions/' . $this->session->id, ['closing_cash_balance' => 1500])
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/cash-register-sessions/rejoin-or-start', [
                'cash_register_id' => $this->cashRegister->id,
                'original_opener_id' => $this->owner->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/cash-register-sessions/' . $this->session->id . '/summary')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->putJson('/api/v1/cash-register-sessions/' . $this->session->id, ['closing_cash_balance' => 100])
            ->assertStatus(401);
    }
}
