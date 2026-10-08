<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers GET /cash-register-sessions/current: the shift of the user, the free
 * terminals, the sessions they could join and the bank accounts to declare.
 */
class CashRegisterSessionApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();
    }

    #[Test]
    public function it_returns_the_active_session_with_its_totals(): void
    {
        $owner = $this->ownerUser();

        $cashRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 1',
            'is_active' => true,
            'in_use' => true,
        ]);

        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $cashRegister->id,
            'user_id' => $owner->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subHour(),
            'closed_at' => null,
            'opening_cash_balance' => 1500,
        ]);
        $session->users()->attach($owner->id);

        Payment::factory()->create([
            'cash_register_session_id' => $session->id,
            'amount' => 1250.50,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);
        Payment::factory()->create([
            'cash_register_session_id' => $session->id,
            'amount' => 800,
            'payment_method' => PaymentMethod::CARD,
            'status' => PaymentStatus::COMPLETED,
        ]);
        // Ignored: the payment never completed.
        Payment::factory()->create([
            'cash_register_session_id' => $session->id,
            'amount' => 999,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::PROCESSING,
        ]);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/cash-register-sessions/current');

        $response->assertOk()
            ->assertJsonPath('active_session.id', $session->id)
            ->assertJsonPath('active_session.status', 'abierta')
            ->assertJsonPath('active_session.opening_cash_balance', 1500)
            ->assertJsonPath('active_session.cash_register.id', $cashRegister->id)
            ->assertJsonPath('active_session.cash_register.name', 'Caja 1')
            ->assertJsonPath('active_session.opener.id', $owner->id)
            ->assertJsonPath('active_session.users.0.id', $owner->id)
            ->assertJsonPath('active_session.totals.cash', 1250.5)
            ->assertJsonPath('active_session.totals.card', 800)
            ->assertJsonPath('active_session.totals.transfer', 0)
            ->assertJsonPath('active_session.totals.balance', 0)
            ->assertJsonPath('available_cash_registers', [])
            ->assertJsonPath('joinable_sessions', []);
    }

    #[Test]
    public function it_offers_the_free_registers_and_the_bank_accounts_when_there_is_no_session(): void
    {
        $owner = $this->ownerUser();

        $freeRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 3',
            'is_active' => true,
            'in_use' => false,
        ]);
        CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 4',
            'is_active' => true,
            'in_use' => true,
        ]);
        CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 5',
            'is_active' => false,
            'in_use' => false,
        ]);

        $account = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'BBVA',
            'account_name' => 'Cuenta principal',
            'card_number' => '4111111111114471',
            'balance' => 5000,
        ]);
        $account->branches()->attach($this->branch->id);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/cash-register-sessions/current');

        $response->assertOk()
            ->assertJsonPath('active_session', null)
            ->assertJsonCount(1, 'available_cash_registers')
            ->assertJsonPath('available_cash_registers.0.id', $freeRegister->id)
            ->assertJsonPath('available_cash_registers.0.name', 'Caja 3')
            ->assertJsonCount(1, 'bank_accounts')
            ->assertJsonPath('bank_accounts.0.id', $account->id)
            ->assertJsonPath('bank_accounts.0.name', 'Cuenta principal - BBVA (...4471)')
            ->assertJsonPath('bank_accounts.0.bank_name', 'BBVA')
            ->assertJsonPath('bank_accounts.0.balance', '5000.00');
    }

    #[Test]
    public function it_offers_the_sessions_the_user_could_join(): void
    {
        $owner = $this->ownerUser();
        $opener = $this->employeeUser(['pos.access'], ['email' => 'jose@test.com']);

        $busyRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 2',
            'is_active' => true,
            'in_use' => true,
        ]);

        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $busyRegister->id,
            'user_id' => $opener->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subMinutes(30),
            'closed_at' => null,
        ]);
        $session->users()->attach($opener->id);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/cash-register-sessions/current');

        $response->assertOk()
            ->assertJsonPath('active_session', null)
            ->assertJsonPath('available_cash_registers', [])
            ->assertJsonCount(1, 'joinable_sessions')
            ->assertJsonPath('joinable_sessions.0.id', $session->id)
            ->assertJsonPath('joinable_sessions.0.cash_register.name', 'Caja 2')
            ->assertJsonPath('joinable_sessions.0.opener.id', $opener->id);
    }

    #[Test]
    public function it_requires_the_pos_permission(): void
    {
        $employee = $this->employeeUser(['customers.access']);

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/cash-register-sessions/current')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');
    }

    #[Test]
    public function it_opens_the_cash_register_and_snapshots_the_bank_balances(): void
    {
        $owner = $this->ownerUser();

        $terminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 3',
            'is_active' => true,
            'in_use' => false,
        ]);

        $declared = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'BBVA',
            'account_name' => 'Cuenta principal',
            'balance' => 5000,
        ]);
        $declared->branches()->attach($this->branch->id);

        $inherited = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'Santander',
            'account_name' => 'Caja chica',
            'balance' => 1200,
        ]);
        $inherited->branches()->attach($this->branch->id);

        // Previous cut of this terminal: accounts the cashier does not declare
        // inherit that closing balance instead of the raw current one.
        CashRegisterSession::factory()->create([
            'cash_register_id' => $terminal->id,
            'user_id' => $owner->id,
            'status' => CashRegisterSessionStatus::CLOSED,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subHours(20),
            'closing_bank_balances' => [
                ['id' => $inherited->id, 'account_name' => 'Caja chica', 'bank_name' => 'Santander', 'balance' => 1500],
            ],
        ]);

        $response = $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions', [
                'cash_register_id' => $terminal->id,
                'opening_cash_balance' => 1500,
                'bank_accounts' => [
                    ['id' => $declared->id, 'balance' => 8000],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'La caja ha sido abierta con éxito.')
            ->assertJsonPath('active_session.status', 'abierta')
            ->assertJsonPath('active_session.opening_cash_balance', 1500)
            ->assertJsonPath('active_session.cash_register.id', $terminal->id)
            ->assertJsonPath('active_session.cash_register.name', 'Caja 3')
            ->assertJsonPath('active_session.opener.id', $owner->id)
            ->assertJsonPath('active_session.users.0.id', $owner->id)
            ->assertJsonCount(2, 'active_session.opening_bank_balances')
            ->assertJsonPath('active_session.opening_bank_balances.0.account_name', 'Cuenta principal')
            ->assertJsonPath('active_session.opening_bank_balances.0.balance', 8000)
            ->assertJsonPath('active_session.opening_bank_balances.1.account_name', 'Caja chica')
            ->assertJsonPath('active_session.opening_bank_balances.1.balance', 1500)
            ->assertJsonPath('active_session.totals.cash', 0);

        $session = CashRegisterSession::latest('id')->first();

        $this->assertDatabaseHas('cash_register_sessions', [
            'id' => $session->id,
            'cash_register_id' => $terminal->id,
            'user_id' => $owner->id,
            'status' => CashRegisterSessionStatus::OPEN->value,
        ]);
        $this->assertDatabaseHas('cash_register_session_user', [
            'cash_register_session_id' => $session->id,
            'user_id' => $owner->id,
        ]);
        $this->assertTrue((bool) $terminal->fresh()->in_use);
        // The declared balance was applied, the inherited one was not touched.
        $this->assertEquals(8000, (float) $declared->fresh()->balance);
        $this->assertEquals(1200, (float) $inherited->fresh()->balance);
    }

    #[Test]
    public function it_rejects_opening_a_register_that_is_already_in_use(): void
    {
        $owner = $this->ownerUser();
        $opener = $this->employeeUser(['pos.access']);

        $terminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 2',
            'is_active' => true,
            'in_use' => true,
        ]);

        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $terminal->id,
            'user_id' => $opener->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subMinutes(10),
            'closed_at' => null,
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions', [
                'cash_register_id' => $terminal->id,
                'opening_cash_balance' => 500,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cash_register_in_use')
            ->assertJsonPath('message', 'Parece que otro usuario abrió caja antes que tú. Puedes unirte a la sesión.')
            ->assertJsonPath('session_id', $session->id)
            ->assertJsonPath('cash_register.id', $terminal->id)
            ->assertJsonPath('opened_by.id', $opener->id);

        $this->assertEquals(1, CashRegisterSession::count());
    }

    #[Test]
    public function it_rejects_opening_when_the_user_already_has_an_open_session(): void
    {
        $owner = $this->ownerUser();

        $busyTerminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'in_use' => true,
        ]);
        $activeSession = CashRegisterSession::factory()->create([
            'cash_register_id' => $busyTerminal->id,
            'user_id' => $owner->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'closed_at' => null,
        ]);
        $activeSession->users()->attach($owner->id);

        $freeTerminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'in_use' => false,
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions', [
                'cash_register_id' => $freeTerminal->id,
                'opening_cash_balance' => 500,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_already_open')
            ->assertJsonPath('message', 'Ya tienes una sesión de caja activa.');

        $this->assertFalse((bool) $freeTerminal->fresh()->in_use);
    }

    #[Test]
    public function it_hides_registers_of_another_branch(): void
    {
        $owner = $this->ownerUser();

        $foreignTerminal = CashRegister::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'is_active' => true,
            'in_use' => false,
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions', [
                'cash_register_id' => $foreignTerminal->id,
                'opening_cash_balance' => 500,
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    #[Test]
    public function it_joins_an_open_session_of_the_branch(): void
    {
        $owner = $this->ownerUser();
        $opener = $this->employeeUser(['pos.access']);

        $terminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Caja 2',
            'is_active' => true,
            'in_use' => true,
        ]);

        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $terminal->id,
            'user_id' => $opener->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now()->subMinutes(5),
            'closed_at' => null,
        ]);
        $session->users()->attach($opener->id);

        $response = $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions/' . $session->id . '/join');

        $response->assertOk()
            ->assertJsonPath('message', 'Te has unido a la sesión de caja.')
            ->assertJsonPath('active_session.id', $session->id)
            ->assertJsonCount(2, 'active_session.users');

        $this->assertDatabaseHas('cash_register_session_user', [
            'cash_register_session_id' => $session->id,
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function it_rejects_joining_a_closed_session(): void
    {
        $owner = $this->ownerUser();

        $terminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'in_use' => false,
        ]);

        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $terminal->id,
            'user_id' => $owner->id,
            'status' => CashRegisterSessionStatus::CLOSED,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subHours(20),
        ]);

        $this->withToken($this->tokenFor($owner))
            ->postJson('/api/v1/cash-register-sessions/' . $session->id . '/join')
            ->assertStatus(409)
            ->assertJsonPath('code', 'session_not_open')
            ->assertJsonPath('message', 'Esa sesión de caja ya fue cerrada.');
    }

    #[Test]
    public function it_requires_the_pos_permission_to_open_or_join(): void
    {
        $employee = $this->employeeUser(['customers.access']);
        $token = $this->tokenFor($employee);

        $terminal = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'in_use' => false,
        ]);
        $session = CashRegisterSession::factory()->create([
            'cash_register_id' => $terminal->id,
            'user_id' => $this->ownerUser()->id,
            'status' => CashRegisterSessionStatus::OPEN,
            'closed_at' => null,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/cash-register-sessions', [
                'cash_register_id' => $terminal->id,
                'opening_cash_balance' => 100,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->postJson('/api/v1/cash-register-sessions/' . $session->id . '/join')
            ->assertStatus(403);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/cash-register-sessions/current')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->postJson('/api/v1/cash-register-sessions', ['opening_cash_balance' => 100])
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
