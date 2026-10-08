<?php

namespace Tests\Feature\Api\V1;

use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers GET /bank-accounts: subscription owners see every account of the
 * branch, employees only see the accounts assigned to them (same rule as the web POS).
 */
class BankAccountApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private BankAccount $branchAccount;

    private BankAccount $employeeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->branchAccount = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'BBVA',
            'account_name' => 'Cuenta principal',
            'card_number' => '4111111111114471',
            'balance' => 5000,
        ]);
        $this->branchAccount->branches()->attach($this->branch->id);

        $this->employeeAccount = BankAccount::factory()->create([
            'subscription_id' => $this->subscription->id,
            'bank_name' => 'Santander',
            'account_name' => 'Caja chica',
            'card_number' => '4555555555558205',
            'balance' => 820.50,
        ]);
    }

    #[Test]
    public function it_lists_the_branch_accounts_for_the_owner(): void
    {
        $owner = $this->ownerUser();

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/bank-accounts');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->branchAccount->id)
            ->assertJsonPath('0.name', 'Cuenta principal - BBVA (...4471)')
            ->assertJsonPath('0.bank_name', 'BBVA')
            ->assertJsonPath('0.account_name', 'Cuenta principal')
            ->assertJsonPath('0.balance', '5000.00');
    }

    #[Test]
    public function it_lists_only_the_assigned_accounts_for_an_employee(): void
    {
        $employee = $this->employeeUser(['pos.access']);
        $employee->bankAccounts()->attach($this->employeeAccount->id);

        $response = $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/bank-accounts');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->employeeAccount->id)
            ->assertJsonPath('0.name', 'Caja chica - Santander (...8205)')
            ->assertJsonPath('0.balance', '820.50');
    }

    #[Test]
    public function it_requires_the_pos_permission(): void
    {
        $employee = $this->employeeUser(['customers.access']);

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/bank-accounts')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');
    }
}
