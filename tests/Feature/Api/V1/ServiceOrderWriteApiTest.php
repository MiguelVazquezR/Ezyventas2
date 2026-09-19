<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\Feature\Api\V1\Concerns\BuildsPosApiContext;
use Tests\TestCase;

/**
 * Covers the service order write endpoints of the mobile API phase 3: create,
 * edit, repair of old orders and advance payments.
 */
class ServiceOrderWriteApiTest extends TestCase
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
     * Order of 150 with one unit of the product of the branch.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'create_customer' => false,
            'customer_name' => 'Ana Ramírez',
            'customer_phone' => '4771112233',
            'customer_address' => ['street' => 'Av. Hidalgo 120', 'city' => 'León'],
            'item_description' => 'iPhone 13, pantalla rota',
            'reported_problems' => 'No enciende después de una caída',
            'promised_at' => now()->addDays(3)->toDateTimeString(),
            'assign_technician' => true,
            'technician_name' => 'Luis Torres',
            'technician_commission_type' => 'percentage',
            'technician_commission_value' => 20,
            'custom_fields' => ['pin_desbloqueo' => '1234'],
            'items' => [[
                'itemable_id' => $this->product->id,
                'itemable_type' => Product::class,
                'description' => $this->product->name,
                'quantity' => 1,
                'unit_price' => 150,
                'line_total' => 150,
            ]],
            'subtotal' => 150,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'discount_amount' => 0,
            'final_total' => 150,
            'cash_register_session_id' => $this->cashRegisterSession->id,
        ], $overrides);
    }

    #[Test]
    public function it_creates_an_order_with_its_linked_sale_and_evidence(): void
    {
        Storage::fake('public');

        $response = $this->withToken($this->token)->post('/api/v1/service-orders', array_merge($this->orderPayload(), [
            'initial_evidence_images' => [$this->evidenceImage('equipo-1.jpg')],
        ]));

        $response->assertCreated()
            ->assertJsonPath('message', 'Orden de servicio creada.')
            ->assertJsonPath('service_order.folio', 'OS-001')
            ->assertJsonPath('service_order.status', 'pendiente')
            ->assertJsonPath('service_order.customer.id', $this->customer->id)
            ->assertJsonPath('service_order.final_total', '150.00')
            ->assertJsonPath('service_order.has_transaction', true)
            ->assertJsonPath('service_order.transaction.folio', 'OS-V-001')
            ->assertJsonPath('service_order.transaction.status', 'pendiente')
            ->assertJsonPath('service_order.transaction.remaining_due', 150)
            ->assertJsonPath('service_order.amount_due', 150)
            ->assertJsonCount(1, 'service_order.items')
            ->assertJsonCount(1, 'service_order.media.initial_service_order_evidence');

        $serviceOrder = ServiceOrder::latest('id')->first();

        $this->assertEquals(ServiceOrderStatus::PENDING, $serviceOrder->status);
        $this->assertDatabaseHas('transactions', [
            'transactionable_type' => ServiceOrder::class,
            'transactionable_id' => $serviceOrder->id,
            'channel' => TransactionChannel::SERVICE_ORDER->value,
            'status' => TransactionStatus::PENDING->value,
            'subtotal' => 150,
        ]);
        // The customer owes the order and the stock already left the shelf.
        $this->assertEquals(-150, (float) $this->customer->fresh()->balance);
        $this->assertEquals(19, $this->productStock()->current_stock);
    }

    #[Test]
    public function it_creates_the_customer_on_the_fly(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/service-orders', $this->orderPayload([
                'customer_id' => null,
                'create_customer' => true,
                'credit_limit' => 2000,
                'customer_name' => 'Beto Sánchez',
                'customer_phone' => '4779998877',
            ]));

        $response->assertCreated()
            ->assertJsonPath('service_order.customer_name', 'Beto Sánchez')
            ->assertJsonPath('service_order.customer.phone', '4779998877');

        $this->assertDatabaseHas('customers', [
            'branch_id' => $this->branch->id,
            'name' => 'Beto Sánchez',
            'phone' => '4779998877',
            'credit_limit' => 2000,
        ]);
    }

    #[Test]
    public function it_requires_an_open_session_of_the_branch_to_create_an_order(): void
    {
        // Closed session: rejected by the validation rules.
        $this->cashRegisterSession->update(['status' => CashRegisterSessionStatus::CLOSED]);

        $this->withToken($this->token)
            ->postJson('/api/v1/service-orders', $this->orderPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Necesitas una sesión de caja abierta para crear la orden.');

        // Open session of another branch: rejected by the session guard.
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
            ->postJson('/api/v1/service-orders', $this->orderPayload([
                'cash_register_session_id' => $foreignSession->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_required');

        $this->assertEquals(0, ServiceOrder::count());
    }

    #[Test]
    public function it_updates_an_order_and_adjusts_the_stock(): void
    {
        $serviceOrder = $this->createOrder([
            'items' => [[
                'itemable_id' => $this->product->id,
                'itemable_type' => Product::class,
                'description' => $this->product->name,
                'quantity' => 2,
                'unit_price' => 150,
                'line_total' => 300,
            ]],
            'subtotal' => 300,
            'final_total' => 300,
        ]);

        $this->assertEquals(18, $this->productStock()->current_stock);
        $this->assertEquals(-300, (float) $this->customer->fresh()->balance);

        // The technician keeps only one unit: the other one goes back.
        $response = $this->withToken($this->token)
            ->putJson('/api/v1/service-orders/' . $serviceOrder->id, $this->orderPayload([
                'technician_diagnosis' => 'Display dañado y batería al 62 %.',
            ]));

        $response->assertOk()
            ->assertJsonPath('message', 'Orden de servicio actualizada.')
            ->assertJsonPath('service_order.final_total', '150.00')
            ->assertJsonPath('service_order.technician_diagnosis', 'Display dañado y batería al 62 %.')
            ->assertJsonCount(1, 'service_order.items')
            ->assertJsonPath('service_order.items.0.quantity', 1)
            ->assertJsonPath('service_order.transaction.total', 150);

        $this->assertEquals(19, $this->productStock()->current_stock);
        // The customer debt follows the new total.
        $this->assertEquals(-150, (float) $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_removes_evidence_photos_of_an_order(): void
    {
        Storage::fake('public');

        $serviceOrder = $this->createOrder([
            'initial_evidence_images' => [
                $this->evidenceImage('equipo-1.jpg'),
                $this->evidenceImage('equipo-2.jpg'),
            ],
        ]);

        $mediaIds = $serviceOrder->getMedia('initial-service-order-evidence')->pluck('id')->all();
        $this->assertCount(2, $mediaIds);

        $this->withToken($this->token)
            ->putJson('/api/v1/service-orders/' . $serviceOrder->id, $this->orderPayload([
                'deleted_media_ids' => [$mediaIds[0]],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'service_order.media.initial_service_order_evidence');

        $this->assertSame(1, $serviceOrder->fresh()->getMedia('initial-service-order-evidence')->count());
    }

    #[Test]
    public function it_creates_the_linked_sale_of_an_old_order(): void
    {
        $oldOrder = ServiceOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 500,
            'discount_amount' => 0,
            'final_total' => 500,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/service-orders/' . $oldOrder->id . '/ensure-transaction');

        $response->assertOk();

        $transactionId = $response->json('transaction_id');
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transactionable_type' => ServiceOrder::class,
            'transactionable_id' => $oldOrder->id,
            'channel' => TransactionChannel::SERVICE_ORDER->value,
            'status' => TransactionStatus::PENDING->value,
            'subtotal' => 500,
        ]);

        // Calling it again returns the same sale instead of creating another one.
        $this->withToken($this->token)
            ->postJson('/api/v1/service-orders/' . $oldOrder->id . '/ensure-transaction')
            ->assertOk()
            ->assertJsonPath('transaction_id', $transactionId);

        $this->assertEquals(1, Transaction::where('transactionable_id', $oldOrder->id)->count());
    }

    #[Test]
    public function it_registers_an_advance_payment_on_an_order(): void
    {
        $serviceOrder = $this->createOrder();

        $response = $this->withToken($this->token)
            ->postJson('/api/v1/service-orders/' . $serviceOrder->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'use_balance' => false,
                'payments' => [['amount' => 100, 'method' => 'efectivo', 'bank_account_id' => null, 'notes' => 'Anticipo']],
            ]);

        $response->assertOk()
            ->assertJsonPath('transaction.total_paid', 100)
            ->assertJsonPath('transaction.remaining_due', 50)
            ->assertJsonPath('service_order.total_paid', 100)
            ->assertJsonPath('service_order.amount_due', 50)
            ->assertJsonPath('print.type', 'abono')
            ->assertJsonPath('print.payload.kind', 'abono')
            ->assertJsonPath('print.payload.folio', 'OS-V-001')
            ->assertJsonPath('print.payload.previousDue', '$150.00 MXN')
            ->assertJsonPath('print.payload.abonado', '$100.00 MXN')
            ->assertJsonPath('print.payload.remainingDue', '$50.00 MXN');

        // The customer debt goes down with the advance.
        $this->assertEquals(-50, (float) $this->customer->fresh()->balance);
    }

    #[Test]
    public function it_requires_the_service_order_permissions(): void
    {
        $serviceOrder = $this->createOrder();
        $employee = $this->employeeUser(['pos.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->postJson('/api/v1/service-orders', $this->orderPayload())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->putJson('/api/v1/service-orders/' . $serviceOrder->id, $this->orderPayload())
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/service-orders/' . $serviceOrder->id . '/ensure-transaction')
            ->assertStatus(403);

        $this->withToken($token)
            ->postJson('/api/v1/service-orders/' . $serviceOrder->id . '/payments', [
                'cash_register_session_id' => $this->cashRegisterSession->id,
                'payments' => [['amount' => 100, 'method' => 'efectivo', 'bank_account_id' => null]],
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function it_deletes_an_order_and_its_linked_sale(): void
    {
        $serviceOrder = $this->createOrder();
        $transactionId = (int) $serviceOrder->transaction->id;

        $this->withToken($this->token)
            ->deleteJson('/api/v1/service-orders/' . $serviceOrder->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('service_orders', ['id' => $serviceOrder->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $transactionId]);
    }

    #[Test]
    public function it_requires_the_delete_permission(): void
    {
        $serviceOrder = $this->createOrder();
        $employee = $this->employeeUser(['services.orders.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->deleteJson('/api/v1/service-orders/' . $serviceOrder->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->assertDatabaseHas('service_orders', ['id' => $serviceOrder->id]);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $serviceOrder = ServiceOrder::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/v1/service-orders', $this->orderPayload())
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->postJson('/api/v1/service-orders/' . $serviceOrder->id . '/ensure-transaction')
            ->assertStatus(401);

        $this->deleteJson('/api/v1/service-orders/' . $serviceOrder->id)
            ->assertStatus(401);
    }

    /**
     * Creates the order through the endpoint (the flow the app follows).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(array $overrides = []): ServiceOrder
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/service-orders', $this->orderPayload($overrides))
            ->assertCreated();

        return ServiceOrder::latest('id')->first();
    }
}
