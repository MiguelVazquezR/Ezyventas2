<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\Feature\Api\V1\Concerns\BuildsPosApiContext;
use Tests\TestCase;

/**
 * Covers the POS write endpoints of the mobile API phase 3: checkout, layaway
 * and order (pedido).
 */
class PosApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;
    use BuildsPosApiContext;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->token = $this->tokenFor($this->owner);
        $this->setUpPosApiContext($this->owner);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'cash_register_session_id' => $this->cashRegisterSession->id,
            'customerId' => $this->customer->id,
            'cartItems' => [$this->cartItem()],
            'subtotal' => 300,
            'total_discount' => 0,
            'total' => 300,
            'use_balance' => false,
            'payments' => [
                ['amount' => 500, 'method' => 'efectivo', 'bank_account_id' => null, 'notes' => null],
            ],
        ], $overrides);
    }

    #[Test]
    public function it_registers_a_cash_sale_and_returns_the_change(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload());

        $response->assertCreated()
            ->assertJsonPath('transaction.status', 'completado')
            ->assertJsonPath('transaction.channel', 'punto_de_venta')
            ->assertJsonPath('transaction.subtotal', '300.00')
            ->assertJsonPath('transaction.total', 300)
            ->assertJsonPath('transaction.total_paid', 300)
            ->assertJsonPath('transaction.remaining_due', 0)
            ->assertJsonPath('transaction.customer.id', $this->customer->id)
            ->assertJsonPath('change', 200)
            ->assertJsonPath('print.data_source_type', 'pos');

        $transaction = Transaction::latest('id')->first();

        $this->assertDatabaseHas('transactions_items', [
            'transaction_id' => $transaction->id,
            'itemable_id' => $this->product->id,
            'quantity' => 2,
        ]);
        // The server caps the stored payment to the amount due (300) and
        // returns the change of the cash received (200).
        $this->assertDatabaseHas('payments', [
            'transaction_id' => $transaction->id,
            'amount' => 300,
            'payment_method' => 'efectivo',
        ]);

        // Stock is deducted from the pivot of the branch.
        $this->assertEquals(18, $this->productStock()->current_stock);
        // The sale belongs to the open shift.
        $this->assertEquals($this->cashRegisterSession->id, $transaction->cash_register_session_id);
    }

    #[Test]
    public function it_registers_a_credit_sale_and_creates_the_customer_debt(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload(['payments' => []]));

        $response->assertCreated()
            ->assertJsonPath('transaction.status', 'pendiente')
            ->assertJsonPath('transaction.total_paid', 0)
            ->assertJsonPath('transaction.remaining_due', 300)
            ->assertJsonPath('change', 0);

        $this->assertEquals(-300, (float) $this->customer->fresh()->balance);
        $this->assertDatabaseHas('customer_balance_movements', [
            'customer_id' => $this->customer->id,
            'amount' => -300,
        ]);
    }

    #[Test]
    public function it_rejects_a_credit_sale_without_customer_or_without_credit(): void
    {
        // No customer: the app must ask for one before leaving a balance.
        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload([
                'customerId' => null,
                'payments' => [],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'customer_required')
            ->assertJsonPath('message', 'Selecciona un cliente para dejar saldo pendiente.');

        // Not enough credit: 300 pending against a 200 limit.
        $poorCustomer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'balance' => 0,
            'credit_limit' => 200,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload([
                'customerId' => $poorCustomer->id,
                'payments' => [],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'credit_limit_exceeded')
            ->assertJsonPath('message', 'El cliente no tiene crédito disponible suficiente.');

        $this->assertEquals(0, (float) $poorCustomer->fresh()->balance);
    }

    #[Test]
    public function it_registers_a_layaway_and_reserves_the_stock(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pos/layaway', $this->salePayload([
                'payments' => [['amount' => 50, 'method' => 'efectivo', 'bank_account_id' => null]],
                'layaway_expiration_date' => now()->addWeek()->toDateString(),
            ]));

        $response->assertCreated()
            ->assertJsonPath('transaction.status', 'apartado')
            ->assertJsonPath('transaction.remaining_due', 250);

        $stock = $this->productStock();
        $this->assertEquals(20, $stock->current_stock, 'El stock físico no debe cambiar en un apartado.');
        $this->assertEquals(2, $stock->reserved_stock, 'El stock reservado debe incrementarse.');
        $this->assertEquals(-250, (float) $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_requires_a_future_expiration_date_for_a_layaway(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/pos/layaway', $this->salePayload([
                'layaway_expiration_date' => now()->subDay()->toDateString(),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'layaway_expiration_date' => 'La fecha límite del apartado debe ser posterior a hoy.',
            ]);
    }

    #[Test]
    public function it_registers_an_order_with_contact_data_and_reserved_stock(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/pos/store-order', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'customerId' => $this->customer->id,
                'cartItems' => [$this->cartItem()],
                'subtotal' => 300,
                'total_discount' => 0,
                'shipping_cost' => 80,
                'contact_info' => ['name' => 'Ana Ramírez', 'type' => 'pedido'],
                'delivery_date' => now()->addDays(2)->toDateTimeString(),
                'shipping_address' => 'Av. Hidalgo 120, León',
                'notes' => 'Entregar después de las 6 pm',
            ]);

        $response->assertCreated()
            ->assertJsonPath('transaction.status', 'por_entregar')
            ->assertJsonPath('transaction.is_order', true)
            ->assertJsonPath('transaction.total', 380)
            ->assertJsonPath('transaction.customer.id', $this->customer->id)
            ->assertJsonPath('print.data_source_type', 'order');

        $transaction = Transaction::latest('id')->first();

        $this->assertEquals(TransactionStatus::TO_DELIVER, $transaction->status);
        $this->assertEquals(TransactionChannel::POS, $transaction->channel);
        $this->assertEquals('Entregar después de las 6 pm', $transaction->notes);
        // The phone of the customer is inherited by the contact.
        $this->assertEquals('4771112233', $transaction->contact_info['phone']);

        $this->assertEquals(2, $this->productStock()->reserved_stock);
    }

    #[Test]
    public function it_requires_contact_name_and_delivery_date_for_an_order(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/pos/store-order', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'cartItems' => [$this->cartItem()],
                'subtotal' => 300,
                'contact_info' => ['name' => 'A'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'contact_info.name' => 'El nombre del contacto debe tener al menos 2 caracteres.',
                'delivery_date' => 'Indica la fecha de entrega del pedido.',
            ]);
    }

    #[Test]
    public function it_rejects_sales_without_an_open_session_of_the_branch(): void
    {
        // Session of another branch: the app must not be able to book there.
        $foreignRegister = CashRegister::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'is_active' => true,
            'in_use' => true,
        ]);
        $foreignSession = CashRegisterSession::factory()->create([
            'cash_register_id' => $foreignRegister->id,
            'user_id' => $this->owner->id,
            'status' => CashRegisterSessionStatus::OPEN,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload([
                'cash_register_session_id' => $foreignSession->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_required')
            ->assertJsonPath('message', 'Necesitas una sesión de caja abierta para registrar ventas.');

        // Closed session: same answer.
        $this->cashRegisterSession->update(['status' => CashRegisterSessionStatus::CLOSED]);

        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_required');

        $this->assertEquals(0, Transaction::count());
    }

    #[Test]
    public function it_requires_the_bank_account_for_card_payments(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload([
                'payments' => [['amount' => 300, 'method' => 'tarjeta', 'bank_account_id' => null]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'payments.0.bank_account_id' => 'Selecciona la cuenta destino para los pagos con tarjeta o transferencia.',
            ]);
    }

    #[Test]
    public function it_rejects_customers_of_another_branch(): void
    {
        $foreignCustomer = Customer::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/pos/checkout', $this->salePayload(['customerId' => $foreignCustomer->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'customerId' => 'El cliente seleccionado no pertenece a tu sucursal.',
            ]);
    }

    #[Test]
    public function it_requires_the_create_sale_permission(): void
    {
        $employee = $this->employeeUser(['pos.access']);

        $this->withToken($this->tokenFor($employee))
            ->postJson('/api/v1/pos/checkout', $this->salePayload())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->postJson('/api/v1/pos/checkout', $this->salePayload())
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
