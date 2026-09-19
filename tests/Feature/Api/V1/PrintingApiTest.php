<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
