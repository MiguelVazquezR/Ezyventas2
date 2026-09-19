<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the sales history endpoints of the mobile API phase 2.
 */
class TransactionApiTest extends TestCase
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
        ]);
    }

    /**
     * Sale with two lines and a partial cash payment (270 total, 100 paid).
     */
    private function sale(array $attributes = []): Transaction
    {
        $transaction = Transaction::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->branch->users()->first()?->id,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'subtotal' => 300,
            'total_discount' => 30,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'invoiced' => false,
            'delivery_date' => null,
            'created_at' => now()->subDay(),
        ], $attributes));

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'description' => 'Filtro de aceite',
            'quantity' => 2,
            'unit_price' => 135,
            'discount_amount' => 15,
            'discount_reason' => 'Promoción de producto',
            'tax_amount' => 0,
            'line_total' => 240,
        ]);

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'description' => 'Aceite 5W30',
            'quantity' => 1,
            'unit_price' => 60,
            'line_total' => 60,
        ]);

        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 100,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        return $transaction;
    }

    #[Test]
    public function it_lists_the_sales_of_the_branch(): void
    {
        $owner = $this->ownerUser();
        $transaction = $this->sale(['folio' => 'V-014']);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/transactions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $transaction->id)
            ->assertJsonPath('data.0.folio', 'V-014')
            ->assertJsonPath('data.0.status', 'completado')
            ->assertJsonPath('data.0.channel', 'punto_de_venta')
            ->assertJsonPath('data.0.customer.id', $this->customer->id)
            ->assertJsonPath('data.0.customer.name', 'Ana Ramírez')
            ->assertJsonPath('data.0.subtotal', '300.00')
            ->assertJsonPath('data.0.total_discount', '30.00')
            ->assertJsonPath('data.0.total', 270)
            ->assertJsonPath('data.0.total_paid', 100)
            ->assertJsonPath('data.0.remaining_due', 170)
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.is_order', false)
            ->assertJsonPath('data.0.invoiced', false)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_hides_balance_payments_and_sales_of_other_branches(): void
    {
        $owner = $this->ownerUser();
        $this->sale();

        // Balance payments are account movements, not sales.
        $this->sale(['channel' => TransactionChannel::BALANCE_PAYMENT]);

        // Sale of another branch.
        $this->sale(['branch_id' => Branch::factory()->create()->id]);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_filters_sales_by_search_status_and_dates(): void
    {
        $owner = $this->ownerUser();
        $token = $this->tokenFor($owner);

        $this->sale(['folio' => 'V-100']);
        $this->sale(['folio' => 'V-200', 'created_at' => now()->subMonth()]);
        $layaway = $this->sale(['folio' => 'V-300', 'status' => TransactionStatus::ON_LAYAWAY]);

        $this->withToken($token)
            ->getJson('/api/v1/transactions?search=V-200')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', 'V-200');

        $this->withToken($token)
            ->getJson('/api/v1/transactions?status=apartado')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $layaway->id);

        $this->withToken($token)
            ->getJson('/api/v1/transactions?date_start=' . now()->subDay()->toDateString() . '&date_end=' . now()->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($token)
            ->getJson('/api/v1/transactions?sortField=folio&sortOrder=asc')
            ->assertOk()
            ->assertJsonPath('data.0.folio', 'V-100');
    }

    #[Test]
    public function it_returns_the_sale_detail_with_items_and_payments(): void
    {
        $owner = $this->ownerUser();
        $transaction = $this->sale(['folio' => 'V-014']);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/transactions/' . $transaction->id);

        $response->assertOk()
            ->assertJsonPath('id', $transaction->id)
            ->assertJsonPath('folio', 'V-014')
            ->assertJsonPath('branch.id', $this->branch->id)
            ->assertJsonPath('customer.name', 'Ana Ramírez')
            ->assertJsonPath('paid_amount', 100)
            ->assertJsonPath('pending_balance', 170)
            ->assertJsonPath('is_paid', false)
            ->assertJsonPath('invoice', null)
            ->assertJsonPath('cash_register_session.id', $transaction->cash_register_session_id)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.description', 'Filtro de aceite')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.discount_reason', 'Promoción de producto')
            ->assertJsonPath('items.0.line_total', '240.00')
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.amount', '100.00')
            ->assertJsonPath('payments.0.payment_method', 'efectivo')
            ->assertJsonPath('payments.0.status', 'completado')
            ->assertJsonPath('payments.0.bank_account', null);
    }

    #[Test]
    public function it_hides_sales_of_other_branches(): void
    {
        $owner = $this->ownerUser();
        $foreignSale = $this->sale(['branch_id' => Branch::factory()->create()->id]);

        $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/transactions/' . $foreignSale->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    #[Test]
    public function it_requires_the_transactions_permissions(): void
    {
        $employee = $this->employeeUser(['pos.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->getJson('/api/v1/transactions')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->getJson('/api/v1/transactions/1')
            ->assertStatus(403);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/transactions')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
