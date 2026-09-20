<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the printing endpoints of the mobile API phase 4: templates, ESC/POS
 * payload, label payload, HTML fallback and the WhatsApp ticket.
 */
class PrintingApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private User $owner;

    private string $token;

    private PrintTemplate $template;

    private Transaction $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->token = $this->tokenFor($this->owner);

        $this->template = PrintTemplate::factory()->create([
            'subscription_id' => $this->subscription->id,
            'name' => 'Ticket de venta 80 mm',
            'type' => TemplateType::SALE_TICKET,
            'context_type' => TemplateContextType::POS->value,
            'is_default' => true,
            'content' => [
                'config' => ['paperWidth' => '80mm', 'feedLines' => 3],
                'elements' => [
                    ['type' => 'text', 'data' => ['text' => 'Hola Mundo']],
                ],
            ],
        ]);

        $customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Ana Ramírez',
            'phone' => '4771112233',
        ]);

        $this->sale = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'status' => TransactionStatus::COMPLETED,
            'channel' => TransactionChannel::POS,
            'folio' => 'V-014',
            'subtotal' => 270,
            'total_discount' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
            'created_at' => now(),
        ]);

        TransactionItem::create([
            'transaction_id' => $this->sale->id,
            'itemable_type' => Product::class,
            'itemable_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
            'description' => 'Filtro de aceite',
            'quantity' => 2,
            'unit_price' => 135,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'line_total' => 270,
        ]);

        Payment::factory()->create([
            'transaction_id' => $this->sale->id,
            'amount' => 300,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);
    }

    #[Test]
    public function it_lists_the_print_templates_of_the_business(): void
    {
        // A template of another business must never show up.
        PrintTemplate::factory()->create(['subscription_id' => $this->branch->subscription_id + 1]);

        $response = $this->withToken($this->token)->getJson('/api/v1/print/templates');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->template->id)
            ->assertJsonPath('0.name', 'Ticket de venta 80 mm')
            ->assertJsonPath('0.type', 'ticket_venta')
            ->assertJsonPath('0.context_type', 'pos')
            ->assertJsonPath('0.paper_width', '80mm')
            ->assertJsonPath('0.is_default', true)
            ->assertJsonPath('0.config.feedLines', 3);

        // Filters: a label template is not a sale ticket.
        $this->withToken($this->token)
            ->getJson('/api/v1/print/templates?type=etiqueta')
            ->assertOk()
            ->assertJsonCount(0);

        $this->withToken($this->token)
            ->getJson('/api/v1/print/templates?context=pos&type=ticket_venta')
            ->assertOk()
            ->assertJsonCount(1);
    }

    #[Test]
    public function it_returns_the_esc_pos_commands_in_base64(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/print/bluetooth-payload', [
                'template_id' => $this->template->id,
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
            ]);

        $response->assertOk()->assertJsonPath('paperWidth', '80mm');

        $commands = base64_decode($response->json('commands_base64'), true);
        $this->assertIsString($commands);
        $this->assertStringContainsString('Hola Mundo', $commands);

        // With the drawer opened, the first bytes are the ESC/POS pulse.
        $withDrawer = $this->withToken($this->token)
            ->postJson('/api/v1/print/bluetooth-payload', [
                'template_id' => $this->template->id,
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
                'open_drawer' => true,
            ]);

        $withDrawer->assertOk();
        $this->assertStringStartsWith(
            "\x1Bp\x00\x19\xFA",
            base64_decode($withDrawer->json('commands_base64'), true)
        );
    }

    #[Test]
    public function it_returns_the_label_operations_and_the_ticket_html(): void
    {
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);
        $labelTemplate = PrintTemplate::factory()->create([
            'subscription_id' => $this->subscription->id,
            'name' => 'Etiqueta 50x30',
            'type' => TemplateType::LABEL,
            'context_type' => TemplateContextType::PRODUCT->value,
            'content' => [
                'config' => ['width' => 50, 'height' => 30, 'gap' => 2, 'dpi' => 203, 'feedLines' => 2],
                'elements' => [
                    ['type' => 'text', 'data' => ['value' => 'Etiqueta', 'x' => 2, 'y' => 2]],
                ],
            ],
        ]);

        $labelPayload = $this->withToken($this->token)
            ->postJson('/api/v1/print/payload', [
                'template_id' => $labelTemplate->id,
                'data_source_type' => 'product',
                'data_source_id' => $product->id,
                'offset_x' => 10,
                'offset_y' => 20,
            ]);

        $labelPayload->assertOk()
            ->assertJsonStructure(['operations', 'paperWidth', 'feedLines'])
            ->assertJsonPath('operations.0.nombre', 'EscribirTexto')
            ->assertJsonPath('paperWidth', '80mm');

        // The label commands travel inside the operation arguments.
        $this->assertStringContainsString('SIZE 50 mm,30 mm', $labelPayload->json('operations.0.argumentos.0'));
        $this->assertStringContainsString('Etiqueta', $labelPayload->json('operations.0.argumentos.0'));

        $html = $this->withToken($this->token)
            ->postJson('/api/v1/print/ticket-html', [
                'template_id' => $this->template->id,
                'data_source_type' => 'transaction',
                'data_source_id' => $this->sale->id,
            ]);

        $html->assertOk()
            ->assertJsonPath('paperWidth', '80mm')
            ->assertJsonPath('template_name', 'Ticket de venta 80 mm');

        $this->assertStringContainsString('Hola Mundo', $html->json('html'));
    }

    #[Test]
    public function it_returns_the_whatsapp_ticket_of_a_sale(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'transaction',
                'data_source_id' => $this->sale->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('ticket.kind', 'sale')
            ->assertJsonPath('ticket.folio', 'V-014')
            ->assertJsonPath('ticket.customer', 'Ana Ramírez')
            ->assertJsonPath('ticket.items.0.cantidad', 2)
            ->assertJsonPath('ticket.items.0.descripcion', 'Filtro de aceite')
            ->assertJsonPath('ticket.items.0.total', '$270.00')
            ->assertJsonPath('ticket.total', '$270.00 MXN')
            ->assertJsonPath('ticket.saleType', 'contado')
            ->assertJsonPath('ticket.paymentMethod', 'Efectivo (Pagado: $300.00 | Cambio: $30.00)')
            ->assertJsonPath('ticket.finalMessage', '¡Gracias por tu compra!')
            ->assertJsonPath('customer_phone', '4771112233')
            ->assertJsonPath('customer_id', $this->sale->customer_id);
    }

    #[Test]
    public function it_returns_the_order_ticket_for_a_pending_delivery(): void
    {
        $this->sale->update([
            'status' => TransactionStatus::TO_DELIVER,
            'delivery_status' => 'pending',
            'contact_info' => ['name' => 'Ana Ramírez', 'phone' => '4779998877', 'type' => 'pedido'],
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'order',
                'data_source_id' => $this->sale->id,
            ])
            ->assertOk()
            ->assertJsonPath('ticket.kind', 'order')
            ->assertJsonPath('customer_phone', '4779998877');
    }

    #[Test]
    public function it_hides_templates_and_documents_of_another_subscription(): void
    {
        $foreignTemplate = PrintTemplate::factory()->create(['subscription_id' => $this->branch->subscription_id + 1]);

        $this->withToken($this->token)
            ->postJson('/api/v1/print/bluetooth-payload', [
                'template_id' => $foreignTemplate->id,
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
            ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');

        // Document of another business: the app must not print it either.
        $foreignSale = Transaction::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'transaction',
                'data_source_id' => $foreignSale->id,
            ])
            ->assertStatus(404);
    }

    #[Test]
    public function it_requires_an_operational_permission(): void
    {
        $employee = $this->employeeUser(['customers.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->getJson('/api/v1/print/templates')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'transaction',
                'data_source_id' => $this->sale->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function it_validates_the_template_and_the_source_type(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/print/bluetooth-payload', [
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selecciona la plantilla de impresión.');

        $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'inventado',
                'data_source_id' => $this->sale->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'data_source_type' => 'El tipo de documento no es válido.',
            ]);
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/print/templates')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->postJson('/api/v1/print/whatsapp-ticket', [
            'data_source_type' => 'transaction',
            'data_source_id' => $this->sale->id,
        ])->assertStatus(401);
    }

    /**
     * The logo of the template must travel inside the ESC/POS bytes: the phone
     * cannot download it, so the server rasterizes it (hallazgo 35).
     */
    #[Test]
    public function it_embeds_the_logo_of_the_template_in_the_esc_pos_payload(): void
    {
        $logoUrl = 'https://ezyventas.test/storage/logo.png';

        $this->template->update(['content' => [
            'config' => ['paperWidth' => '80mm', 'feedLines' => 0],
            'elements' => [
                ['type' => 'local_image', 'data' => ['url' => $logoUrl]],
                ['type' => 'text', 'data' => ['text' => 'Hola Mundo']],
            ],
        ]]);

        Http::fake([$logoUrl => Http::response($this->logoBytes(), 200, ['Content-Type' => 'image/png'])]);

        $commands = base64_decode(
            $this->withToken($this->token)->postJson('/api/v1/print/bluetooth-payload', [
                'template_id' => $this->template->id,
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
            ])->assertOk()->json('commands_base64'),
            true
        );

        // The image arrives as a raster bitmap (GS v 0) of 72 bytes per row,
        // which is exactly the 576 dots of the 80 mm paper.
        $this->assertStringContainsString("\x1D\x76\x30\x00" . chr(72) . chr(0), $commands);
        $this->assertStringContainsString('Hola Mundo', $commands);
        Http::assertSent(fn ($request) => $request->url() === $logoUrl);
    }

    /**
     * A logo that cannot be read never breaks the ticket: it prints without it.
     */
    #[Test]
    public function it_prints_the_ticket_without_the_logo_when_it_cannot_be_read(): void
    {
        $logoUrl = 'https://ezyventas.test/storage/roto.png';

        $this->template->update(['content' => [
            'config' => ['paperWidth' => '58mm', 'feedLines' => 0],
            'elements' => [
                ['type' => 'local_image', 'data' => ['url' => $logoUrl]],
                ['type' => 'text', 'data' => ['text' => 'Hola Mundo']],
            ],
        ]]);

        Http::fake([$logoUrl => Http::response('esto no es una imagen', 200)]);

        $commands = base64_decode(
            $this->withToken($this->token)->postJson('/api/v1/print/bluetooth-payload', [
                'template_id' => $this->template->id,
                'data_source_type' => 'pos',
                'data_source_id' => $this->sale->id,
            ])->assertOk()->json('commands_base64'),
            true
        );

        $this->assertStringNotContainsString("\x1D\x76\x30\x00", $commands);
        $this->assertStringContainsString('Hola Mundo', $commands);
    }

    /**
     * Real PNG bytes for the fake logo (GD is available in the test runtime).
     */
    private function logoBytes(): string
    {
        $image = imagecreatetruecolor(120, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 10, 10, 110, 30, imagecolorallocate($image, 0, 0, 0));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * A service order must have its WhatsApp ticket: the endpoint answered
     * `ticket: null` for every source that was not a sale (hallazgo 16).
     */
    #[Test]
    public function it_builds_the_whatsapp_ticket_of_a_service_order(): void
    {
        $serviceOrder = ServiceOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'customer_id' => $this->sale->customer_id,
            'status' => ServiceOrderStatus::IN_PROGRESS,
            'folio' => 'OS-014',
            'technician_name' => 'Luis Torres',
            'item_description' => 'iPhone 13, pantalla rota',
            'subtotal' => 1450,
            'discount_amount' => 50,
            'final_total' => 1400,
            'received_at' => now()->subDays(2),
            'promised_at' => now()->addDays(2),
        ]);

        ServiceOrderItem::create([
            'service_order_id' => $serviceOrder->id,
            'description' => 'Cambio de pantalla (original)',
            'quantity' => 1,
            'unit_price' => 1450,
            'line_total' => 1450,
        ]);

        $linkedSale = Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->sale->customer_id,
            'user_id' => $this->owner->id,
            'transactionable_type' => ServiceOrder::class,
            'transactionable_id' => $serviceOrder->id,
            'status' => TransactionStatus::PENDING,
            'channel' => TransactionChannel::SERVICE_ORDER,
            'subtotal' => 1400,
            'total_discount' => 0,
            'total_tax' => 0,
            'shipping_cost' => 0,
        ]);

        Payment::factory()->create([
            'transaction_id' => $linkedSale->id,
            'amount' => 700,
            'payment_method' => PaymentMethod::CASH,
            'status' => PaymentStatus::COMPLETED,
        ]);

        $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'service_order',
                'data_source_id' => $serviceOrder->id,
            ])
            ->assertOk()
            ->assertJsonPath('ticket.kind', 'service_order')
            ->assertJsonPath('ticket.title', 'ORDEN DE SERVICIO')
            ->assertJsonPath('ticket.folio', 'OS-014')
            ->assertJsonPath('ticket.statusLabel', 'En reparación')
            ->assertJsonPath('ticket.equipment', 'iPhone 13, pantalla rota')
            ->assertJsonPath('ticket.technician', 'Luis Torres')
            ->assertJsonPath('ticket.parts.0.descripcion', 'Cambio de pantalla (original)')
            ->assertJsonPath('ticket.total', '$1,400.00 MXN')
            ->assertJsonPath('ticket.totalPaid', '$700.00 MXN')
            ->assertJsonPath('ticket.remainingDue', '$700.00 MXN')
            ->assertJsonPath('customer_phone', '4771112233')
            ->assertJsonPath('customer_id', $this->sale->customer_id);
    }

    /**
     * A source that cannot produce a WhatsApp ticket says it explicitly instead
     * of answering `200` with `ticket: null`.
     */
    #[Test]
    public function it_rejects_a_whatsapp_ticket_for_a_source_without_ticket(): void
    {
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);

        $this->withToken($this->token)
            ->postJson('/api/v1/print/whatsapp-ticket', [
                'data_source_type' => 'product',
                'data_source_id' => $product->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este documento no tiene ticket de WhatsApp.');
    }
}
