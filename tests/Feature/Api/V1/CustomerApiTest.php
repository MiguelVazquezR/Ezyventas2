<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the customer read endpoints of the mobile API phase 1 and the quick
 * creation used by the POS, including branch isolation of the data.
 */
class CustomerApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'phone' => '4771112233',
            'balance' => -350,
            'credit_limit' => 2000,
        ]);
    }

    #[Test]
    public function it_lists_the_customers_of_the_branch(): void
    {
        $owner = $this->ownerUser();

        Customer::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/customers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->customer->id)
            ->assertJsonPath('data.0.name', 'Ana Ramírez')
            ->assertJsonPath('data.0.phone', '4771112233')
            ->assertJsonPath('data.0.balance', '-350.00')
            ->assertJsonPath('data.0.credit_limit', '2000.00')
            ->assertJsonPath('data.0.available_credit', 1650)
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_searches_customers_by_name_or_phone(): void
    {
        $owner = $this->ownerUser();

        Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Beto Sánchez',
            'phone' => '9998887777',
        ]);

        $token = $this->tokenFor($owner);

        $this->withToken($token)
            ->getJson('/api/v1/customers?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->customer->id);

        $this->withToken($token)
            ->getJson('/api/v1/customers?search=9998887777')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beto Sánchez');
    }

    #[Test]
    public function it_returns_the_customer_profile_with_layaways_and_movements(): void
    {
        $owner = $this->ownerUser();

        $this->customer->update(['balance' => 0]);
        $this->customer->manualBalanceAdjustment('add', 500, 'Abono manual de prueba');

        $layaway = Transaction::factory()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'status' => TransactionStatus::ON_LAYAWAY,
            'folio' => 'V-5001',
            'subtotal' => 300,
        ]);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/customers/' . $this->customer->id);

        $response->assertOk()
            ->assertJsonPath('id', $this->customer->id)
            ->assertJsonPath('name', 'Ana Ramírez')
            ->assertJsonPath('balance', '500.00')
            ->assertJsonPath('layaway_transactions.0.id', $layaway->id)
            ->assertJsonPath('layaway_transactions.0.folio', 'V-5001')
            ->assertJsonPath('balance_movements.0.description', 'Abono manual de prueba')
            ->assertJsonPath('balance_movements.0.amount', '500.00')
            ->assertJsonPath('balance_movements.0.resulting_balance', '500.00')
            ->assertJsonStructure([
                'address',
                'tax_id',
                'layaway_transactions' => [
                    ['id', 'folio', 'created_at', 'total', 'total_paid', 'pending_amount', 'items'],
                ],
                'balance_movements' => [
                    ['date', 'type', 'description', 'amount', 'resulting_balance'],
                ],
            ]);
    }

    #[Test]
    public function it_hides_customers_of_other_branches(): void
    {
        $owner = $this->ownerUser();
        $foreignCustomer = Customer::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/customers/' . $foreignCustomer->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    #[Test]
    public function it_creates_a_customer_for_the_branch(): void
    {
        $employee = $this->employeeUser(['pos.access', 'customers.create']);

        $response = $this->withToken($this->tokenFor($employee))
            ->postJson('/api/v1/customers', [
                'name' => 'Carlos Mendoza',
                'email' => 'carlos@correo.com',
                'phone' => '4771234567',
                'address' => ['street' => 'Av. Hidalgo 120', 'city' => 'León'],
                'credit_limit' => 2000,
                'client_uuid' => '7d4b1c2a-9f31-4a77-b6ce-0f1e2d3c4b5a',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Carlos Mendoza')
            ->assertJsonPath('balance', '0.00')
            ->assertJsonPath('available_credit', 2000);

        $this->assertDatabaseHas('customers', [
            'name' => 'Carlos Mendoza',
            'email' => 'carlos@correo.com',
            'branch_id' => $this->branch->id,
            'balance' => 0,
        ]);
    }

    #[Test]
    public function it_rejects_the_creation_without_the_create_permission(): void
    {
        $employee = $this->employeeUser(['pos.access']);

        $this->withToken($this->tokenFor($employee))
            ->postJson('/api/v1/customers', ['name' => 'Carlos Mendoza'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->assertDatabaseMissing('customers', ['name' => 'Carlos Mendoza']);
    }

    #[Test]
    public function it_validates_the_customer_payload_in_spanish(): void
    {
        $employee = $this->employeeUser(['pos.access', 'customers.create']);

        $this->withToken($this->tokenFor($employee))
            ->postJson('/api/v1/customers', ['email' => 'no-es-un-correo'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Escribe el nombre del cliente.')
            ->assertJsonPath('errors.email.0', 'Escribe un correo electrónico válido.');
    }

    #[Test]
    public function it_requires_a_token_to_read_customers(): void
    {
        $this->getJson('/api/v1/customers')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
