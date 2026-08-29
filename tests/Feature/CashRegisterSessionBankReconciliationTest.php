<?php

namespace Tests\Feature;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashRegisterSessionBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Branch $branch;

    private CashRegister $cashRegister;

    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        $subscription = $this->branch->subscription;
        $subscription->update(['onboarding_completed_at' => now()]);

        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        $this->cashRegister = CashRegister::factory()->create([
            'branch_id' => $this->branch->id,
            'in_use' => false,
        ]);

        $this->bankAccount = BankAccount::factory()->create([
            'subscription_id' => $subscription->id,
            'balance' => 100.00,
        ]);
        $this->bankAccount->branches()->attach($this->branch->id);

        $this->actingAs($this->user);
    }

    private function bankSnapshot(float $balance): array
    {
        return [[
            'id' => $this->bankAccount->id,
            'account_name' => $this->bankAccount->account_name,
            'bank_name' => $this->bankAccount->bank_name,
            'balance' => $balance,
        ]];
    }

    private function createOpenSession(array $bankBalances): CashRegisterSession
    {
        return $this->cashRegister->sessions()->create([
            'user_id' => $this->user->id,
            'opening_cash_balance' => 0,
            'opening_bank_balances' => $bankBalances,
            'status' => CashRegisterSessionStatus::OPEN,
            'opened_at' => now(),
        ]);
    }

    private function createCardPayment(CashRegisterSession $session, float $amount): Transaction
    {
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'cash_register_session_id' => $session->id,
            'customer_id' => null,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => $amount,
        ]);

        $session->payments()->create([
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'payment_method' => PaymentMethod::CARD,
            'status' => PaymentStatus::COMPLETED,
            'bank_account_id' => $this->bankAccount->id,
            'payment_date' => now(),
        ]);

        return $transaction;
    }

    #[Test]
    public function closing_a_session_writes_back_the_bank_balance_and_snapshots_closing_balances(): void
    {
        $session = $this->createOpenSession($this->bankSnapshot(100.00));
        $this->createCardPayment($session, 25.00);

        $session->closeSession(0, null);

        $session->refresh();

        $this->assertEquals(CashRegisterSessionStatus::CLOSED, $session->status);
        $this->assertCount(1, $session->closing_bank_balances);
        $this->assertEquals(125.00, (float) $session->closing_bank_balances[0]['balance']);
        $this->assertEquals(125.00, (float) $this->bankAccount->fresh()->balance);
    }

    #[Test]
    public function closed_session_keeps_frozen_bank_closing_balances_even_if_a_payment_is_deleted_later(): void
    {
        $session = $this->createOpenSession($this->bankSnapshot(100.00));

        $transaction = $this->createCardPayment($session, 25.00);
        $payment = $session->payments()->where('transaction_id', $transaction->id)->first();

        $session->closeSession(0, null);

        // The payment is removed AFTER the session was closed
        $payment->delete();

        $summary = $session->calculateBankAccountSummary($this->user, true);

        $this->assertCount(1, $summary);
        $this->assertEquals(125.00, (float) $summary[0]['final_balance']);
    }

    #[Test]
    public function opening_a_session_inherits_the_previous_closing_balance_for_undeclared_accounts(): void
    {
        // Previous closed session: the account ended with a reconciled balance of 90.00
        $this->cashRegister->sessions()->create([
            'user_id' => $this->user->id,
            'opening_cash_balance' => 0,
            'opening_bank_balances' => $this->bankSnapshot(100.00),
            'closing_bank_balances' => $this->bankSnapshot(90.00),
            'status' => CashRegisterSessionStatus::CLOSED,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay()->addHours(8),
        ]);

        // The account is NOT declared in the payload (simulates a cashier without permission over it)
        $this->post(route('cash-register-sessions.store'), [
            'cash_register_id' => $this->cashRegister->id,
            'opening_cash_balance' => 50,
            'user_id' => $this->user->id,
            'bank_accounts' => [],
        ])->assertRedirect();

        $openSession = $this->cashRegister->sessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->first();

        $this->assertNotNull($openSession);

        $opening = collect($openSession->opening_bank_balances)
            ->firstWhere('id', $this->bankAccount->id);

        $this->assertEquals(90.00, (float) $opening['balance']);
    }

    #[Test]
    public function expenses_registered_before_the_session_opened_are_not_attributed_to_the_session(): void
    {
        $session = $this->createOpenSession($this->bankSnapshot(500.00));
        $session->update(['opened_at' => now()->subHours(2)]);

        $category = ExpenseCategory::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);

        // Expense registered INSIDE the session window -> counted as spent
        Expense::factory()->create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'amount' => 50.00,
            'expense_category_id' => $category->id,
            'expense_date' => now(),
            'status' => ExpenseStatus::PAID,
            'payment_method' => PaymentMethod::CARD,
            'bank_account_id' => $this->bankAccount->id,
            'is_external' => false,
            'created_at' => now()->subHour(),
        ]);

        // Expense registered BEFORE the session opened -> must NOT be counted
        Expense::factory()->create([
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'amount' => 30.00,
            'expense_category_id' => $category->id,
            'expense_date' => now()->subDays(1),
            'status' => ExpenseStatus::PAID,
            'payment_method' => PaymentMethod::CARD,
            'bank_account_id' => $this->bankAccount->id,
            'is_external' => false,
            'created_at' => now()->subHours(3),
        ]);

        $session->closeSession(0, null);

        $session->refresh();

        $this->assertCount(1, $session->closing_bank_balances);
        $this->assertEquals(450.00, (float) $session->closing_bank_balances[0]['balance']);
        $this->assertEquals(450.00, (float) $this->bankAccount->fresh()->balance);
    }
}

