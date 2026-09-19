<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\CustomFieldDefinition;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the service order endpoints of the mobile API phase 2: work list,
 * detail, status change and diagnosis with evidence photos.
 */
class ServiceOrderApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'phone' => '4771112233',
        ]);
    }

    /**
     * Service order of 1400 with one item, ready to be paid.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): ServiceOrder
    {
        $serviceOrder = ServiceOrder::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'customer_id' => $this->customer->id,
            'status' => ServiceOrderStatus::IN_PROGRESS,
            'folio' => 'OS-014',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'technician_name' => 'Luis Torres',
            'item_description' => 'iPhone 13, pantalla rota',
            'custom_fields' => ['pin_desbloqueo' => '1234'],
            'subtotal' => 1450,
            'discount_amount' => 50,
            'final_total' => 1400,
            'received_at' => now()->subDays(2),
            'promised_at' => now()->addDays(2),
        ], $attributes));

        ServiceOrderItem::create([
            'service_order_id' => $serviceOrder->id,
            'description' => 'Cambio de pantalla (original)',
            'quantity' => 1,
            'unit_price' => 1450,
            'line_total' => 1450,
        ]);

        return $serviceOrder;
    }

    /**
     * Links a sale with a 700 payment to the order (1400 total, 700 paid).
     */
    private function linkSaleWithPartialPayment(ServiceOrder $serviceOrder): Transaction
    {
        $transaction = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'user_id' => $this->owner->id,
            'transactionable_type' => ServiceOrder::class,
            'transactionable_id' => $serviceOrder->id,
            'status' => TransactionStatus::PENDING,
            'channel' => TransactionChannel::SERVICE_ORDER,
            'subtotal' => 1400,
            'total_discount' => 0,
            'shipping_cost' => 0,
        ]);

        Payment::factory()->create([
            'transaction_id' => $transaction->id,
            'amount' => 700,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        return $transaction;
    }

    /**
     * Uploaded image written to a real temp path.
     *
     * UploadedFile::fake() relies on an auto-deleting temporary handle that is
     * not always reliable on Windows, which made this test flaky.
     */
    private function evidenceImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evidence');
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path, 80);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /**
     * Uploaded file that is not an image (for the validation test).
     */
    private function notAnImage(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'document');
        file_put_contents($path, '%PDF-1.4 documento de prueba');

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    #[Test]
    public function it_lists_the_service_orders_of_the_branch(): void
    {
        $serviceOrder = $this->order();
        $this->linkSaleWithPartialPayment($serviceOrder);

        $response = $this->withToken($this->tokenFor($this->owner))
            ->getJson('/api/v1/service-orders');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $serviceOrder->id)
            ->assertJsonPath('data.0.folio', 'OS-014')
            ->assertJsonPath('data.0.customer_name', 'Ana Ramírez')
            ->assertJsonPath('data.0.customer_phone', '4771112233')
            ->assertJsonPath('data.0.item_description', 'iPhone 13, pantalla rota')
            ->assertJsonPath('data.0.status', 'en_progreso')
            ->assertJsonPath('data.0.technician_name', 'Luis Torres')
            ->assertJsonPath('data.0.subtotal', '1450.00')
            ->assertJsonPath('data.0.discount_amount', '50.00')
            ->assertJsonPath('data.0.final_total', '1400.00')
            ->assertJsonPath('data.0.total_paid', 700)
            ->assertJsonPath('data.0.amount_due', 700)
            ->assertJsonPath('data.0.has_transaction', true)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_filters_service_orders_by_search_status_and_hides_other_branches(): void
    {
        $token = $this->tokenFor($this->owner);

        $this->order(['folio' => 'OS-020', 'customer_name' => 'Beto Sánchez']);
        $finished = $this->order(['folio' => 'OS-021', 'status' => ServiceOrderStatus::FINISHED]);

        // Order of another branch.
        $this->order(['branch_id' => Branch::factory()->create()->id]);

        $this->withToken($token)
            ->getJson('/api/v1/service-orders?search=OS-020')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Beto Sánchez');

        $this->withToken($token)
            ->getJson('/api/v1/service-orders?status=terminado')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $finished->id);

        $this->withToken($token)
            ->getJson('/api/v1/service-orders?sortField=folio&sortOrder=asc')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.folio', 'OS-020');

        $this->withToken($token)
            ->getJson('/api/v1/service-orders?status=inexistente')
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'El estatus seleccionado no es válido.');
    }

    #[Test]
    public function it_returns_the_order_detail_with_items_evidence_and_history(): void
    {
        Storage::fake('public');

        $serviceOrder = $this->order();
        $transaction = $this->linkSaleWithPartialPayment($serviceOrder);

        $serviceOrder->addMedia(UploadedFile::fake()->image('equipo-1.jpg'))
            ->toMediaCollection('initial-service-order-evidence');

        CustomFieldDefinition::create([
            'subscription_id' => $this->subscription->id,
            'module' => 'service_orders',
            'key' => 'pin_desbloqueo',
            'name' => 'PIN de desbloqueo',
            'type' => 'text',
            'is_required' => false,
        ]);

        activity()
            ->performedOn($serviceOrder)
            ->causedBy($this->owner)
            ->event('updated')
            ->log('La orden de servicio ha sido actualizada');

        $response = $this->withToken($this->tokenFor($this->owner))
            ->getJson('/api/v1/service-orders/' . $serviceOrder->id);

        $response->assertOk()
            ->assertJsonPath('id', $serviceOrder->id)
            ->assertJsonPath('folio', 'OS-014')
            ->assertJsonPath('customer.id', $this->customer->id)
            ->assertJsonPath('customer.phone', '4771112233')
            ->assertJsonPath('custom_fields.pin_desbloqueo', '1234')
            ->assertJsonPath('custom_field_definitions.0.key', 'pin_desbloqueo')
            ->assertJsonPath('custom_field_definitions.0.name', 'PIN de desbloqueo')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.description', 'Cambio de pantalla (original)')
            ->assertJsonPath('items.0.quantity', 1)
            ->assertJsonPath('media.initial_service_order_evidence.0.file_name', 'equipo-1.jpg')
            ->assertJsonPath('media.closing_service_order_evidence', [])
            ->assertJsonPath('transaction.id', $transaction->id)
            ->assertJsonPath('transaction.total_paid', 700)
            ->assertJsonPath('transaction.remaining_due', 700)
            ->assertJsonCount(1, 'transaction.payments')
            ->assertJsonPath('transaction.payments.0.amount', '700.00')
            ->assertJsonPath('activities.0.event', 'updated')
            ->assertJsonPath('activities.0.description', 'La orden de servicio ha sido actualizada')
            ->assertJsonPath('activities.0.causer.name', $this->owner->name);
    }

    #[Test]
    public function it_hides_orders_of_other_branches(): void
    {
        $foreignOrder = $this->order(['branch_id' => Branch::factory()->create()->id]);

        $this->withToken($this->tokenFor($this->owner))
            ->getJson('/api/v1/service-orders/' . $foreignOrder->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    #[Test]
    public function it_changes_the_status_of_an_order(): void
    {
        $serviceOrder = $this->order();

        $response = $this->withToken($this->tokenFor($this->owner))
            ->patchJson('/api/v1/service-orders/' . $serviceOrder->id . '/status', [
                'status' => 'terminado',
                'client_uuid' => '7d4b1c2a-9f31-4a77-b6ce-0f1e2d3c4b5a',
            ]);

        $response->assertOk()
            ->assertJsonPath('service_order.id', $serviceOrder->id)
            ->assertJsonPath('service_order.status', 'terminado')
            ->assertJsonPath('message', 'Estatus de la orden actualizado correctamente.');

        $this->assertDatabaseHas('service_orders', [
            'id' => $serviceOrder->id,
            'status' => ServiceOrderStatus::FINISHED->value,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $serviceOrder->id,
            'event' => 'status_changed',
            'causer_id' => $this->owner->id,
        ]);
    }

    #[Test]
    public function it_rejects_changing_to_the_same_status(): void
    {
        $serviceOrder = $this->order(['status' => ServiceOrderStatus::FINISHED]);

        $this->withToken($this->tokenFor($this->owner))
            ->patchJson('/api/v1/service-orders/' . $serviceOrder->id . '/status', ['status' => 'terminado'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El estatus ya es el seleccionado.')
            ->assertJsonPath('errors.status.0', 'El estatus ya es el seleccionado.');
    }

    #[Test]
    public function it_rejects_an_unknown_status(): void
    {
        $serviceOrder = $this->order();

        $this->withToken($this->tokenFor($this->owner))
            ->patchJson('/api/v1/service-orders/' . $serviceOrder->id . '/status', ['status' => 'volando'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'El estatus seleccionado no es válido.');
    }

    #[Test]
    public function it_saves_the_diagnosis_with_evidence_photos(): void
    {
        Storage::fake('public');

        $serviceOrder = $this->order();

        $response = $this->withToken($this->tokenFor($this->owner))
            ->post('/api/v1/service-orders/' . $serviceOrder->id . '/diagnosis', [
                'technician_diagnosis' => 'Display dañado y batería al 62 %.',
                'closing_evidence_images' => [
                    $this->evidenceImage('cierre-1.jpg'),
                    $this->evidenceImage('cierre-2.jpg'),
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Diagnóstico y evidencias guardados correctamente.')
            ->assertJsonPath('service_order.id', $serviceOrder->id)
            ->assertJsonPath('service_order.technician_diagnosis', 'Display dañado y batería al 62 %.')
            ->assertJsonCount(2, 'service_order.media.closing_service_order_evidence');

        $this->assertDatabaseHas('service_orders', [
            'id' => $serviceOrder->id,
            'technician_diagnosis' => 'Display dañado y batería al 62 %.',
        ]);

        $this->assertSame(2, $serviceOrder->fresh()->getMedia('closing-service-order-evidence')->count());
    }

    #[Test]
    public function it_keeps_the_diagnosis_when_only_photos_are_sent(): void
    {
        Storage::fake('public');

        $serviceOrder = $this->order(['technician_diagnosis' => 'Diagnóstico previo.']);

        $this->withToken($this->tokenFor($this->owner))
            ->post('/api/v1/service-orders/' . $serviceOrder->id . '/diagnosis', [
                'closing_evidence_images' => [$this->evidenceImage('cierre.jpg')],
            ])
            ->assertOk()
            ->assertJsonPath('service_order.technician_diagnosis', 'Diagnóstico previo.');
    }

    #[Test]
    public function it_validates_the_evidence_photos(): void
    {
        Storage::fake('public');

        $serviceOrder = $this->order();

        $response = $this->withToken($this->tokenFor($this->owner))
            ->post('/api/v1/service-orders/' . $serviceOrder->id . '/diagnosis', [
                'closing_evidence_images' => [$this->notAnImage('contrato.pdf')],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['closing_evidence_images.0' => 'Cada archivo debe ser una imagen.']);
    }

    #[Test]
    public function it_requires_the_service_orders_permissions(): void
    {
        $serviceOrder = $this->order();
        $employee = $this->employeeUser(['pos.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->getJson('/api/v1/service-orders')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->getJson('/api/v1/service-orders/' . $serviceOrder->id)
            ->assertStatus(403);

        $this->withToken($token)
            ->patchJson('/api/v1/service-orders/' . $serviceOrder->id . '/status', ['status' => 'terminado'])
            ->assertStatus(403);

        $this->withToken($token)
            ->post('/api/v1/service-orders/' . $serviceOrder->id . '/diagnosis', ['technician_diagnosis' => 'x'])
            ->assertStatus(403);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/service-orders')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }
}
