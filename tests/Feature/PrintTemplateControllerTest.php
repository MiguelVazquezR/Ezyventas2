<?php

namespace Tests\Feature;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Models\Branch;
use App\Models\PrintTemplate;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PrintTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Crear datos base (Sucursal y Usuario)
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->subscription = $this->branch->subscription;

        // 2. Configurar Suscripción Activa (Bypass de Middleware)
        $this->subscription->update(['onboarding_completed_at' => now()]);
        
        $version = SubscriptionVersion::create([
            'subscription_id' => $this->subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Configurar Permisos (Spatie)
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        // Crear todos los permisos necesarios
        $permissions = [
            'settings.templates.access',
            'settings.templates.create',
            'settings.templates.edit',
            'settings.templates.delete',
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'settings']);
        }

        $role = Role::create(['name' => 'Admin', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Autenticar
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_print_templates(): void
    {
        // Arrange: Crear algunas plantillas
        PrintTemplate::factory()->count(3)->create([
            'subscription_id' => $this->subscription->id
        ]);

        // Act
        $response = $this->get(route('print-templates.index'));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Template/Index')
            ->has('templates', 3)
        );
    }

    #[Test]
    public function it_can_show_create_page_for_quote_template(): void
    {
        // Act: Navegar a crear cotización
        $response = $this->get(route('print-templates.create', ['type' => 'cotizacion']));

        // Assert
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Template/CreateQuoteTemplate') // Verifica que cargue el componente correcto
        );
    }

    #[Test]
    public function it_stores_a_quote_template_and_assigns_correct_context(): void
    {
        // Arrange: Payload para una cotización
        $payload = [
            'name' => 'Cotización Corporativa A4',
            'type' => TemplateType::QUOTE->value,
            'branch_ids' => [$this->branch->id],
            'content' => [
                'config' => [
                    'pageSize' => 'a4',
                    'margins' => '2.5cm'
                ],
                'elements' => [
                    ['type' => 'text', 'data' => ['content' => 'Hola Mundo']]
                ]
            ]
        ];

        // Act
        $response = $this->post(route('print-templates.store'), $payload);

        // Assert
        $response->assertRedirect(route('print-templates.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('print_templates', [
            'name' => 'Cotización Corporativa A4',
            'type' => TemplateType::QUOTE->value,
            'context_type' => TemplateContextType::QUOTE->value, // <-- VERIFICACIÓN CLAVE
            'subscription_id' => $this->subscription->id
        ]);
    }

    #[Test]
    public function it_stores_a_ticket_template_and_infers_transaction_context(): void
    {
        // Arrange: Payload para un ticket con variables de venta
        $payload = [
            'name' => 'Ticket Venta 80mm',
            'type' => TemplateType::SALE_TICKET->value,
            'branch_ids' => [$this->branch->id],
            'content' => [
                'config' => ['paperWidth' => '80mm'],
                'elements' => [
                    // Elemento con variable {{folio}} forzará contexto TRANSACTION
                    ['type' => 'text', 'data' => ['text' => 'Folio: {{folio}}']] 
                ]
            ]
        ];

        // Act
        $response = $this->post(route('print-templates.store'), $payload);

        // Assert
        $this->assertDatabaseHas('print_templates', [
            'name' => 'Ticket Venta 80mm',
            'type' => TemplateType::SALE_TICKET->value,
            'context_type' => TemplateContextType::TRANSACTION->value, // <-- Inferencia correcta
        ]);
    }

    #[Test]
    public function it_updates_a_template_successfully(): void
    {
        $template = PrintTemplate::factory()->create([
            'subscription_id' => $this->subscription->id,
            'name' => 'Viejo Nombre'
        ]);

        // Payload válido y completo para evitar errores de validación
        $payload = [
            'name' => 'Nuevo Nombre Actualizado',
            'type' => $template->type->value,
            'branch_ids' => [$this->branch->id],
            'content' => [
                'config' => ['paperWidth' => '80mm'], // Datos mínimos requeridos
                'elements' => [
                    ['type' => 'text', 'data' => ['text' => 'Updated']]
                ]
            ]
        ];

        $response = $this->put(route('print-templates.update', $template), $payload);

        $response->assertRedirect(route('print-templates.index'));
        $this->assertEquals('Nuevo Nombre Actualizado', $template->fresh()->name);
    }

    #[Test]
    public function it_prevents_creating_template_if_limit_reached(): void
    {
        $version = $this->subscription->versions()->latest('start_date')->first();
        
        // CORRECCIÓN: Añadimos 'item_type' que es requerido por tu base de datos
        // Asumo que el tipo para límites es 'feature' o similar. Ajusta el valor según tu Enum o lógica.
        $version->items()->create([
            'item_key' => 'limit_print_templates',
            'item_type' => 'user_limit', // <-- Campo faltante agregado
            'name' => 'Plantillas personalizadas',
            'unit_price' => 7.5,
            'quantity' => 1,
            'price' => 0 
        ]);

        PrintTemplate::factory()->create(['subscription_id' => $this->subscription->id]);

        $payload = [
            'name' => 'Plantilla Excedente',
            'type' => TemplateType::SALE_TICKET->value,
            'branch_ids' => [$this->branch->id],
            // Enviamos datos válidos para que el fallo sea solo por el límite
            'content' => [
                'config' => ['paperWidth' => '80mm'],
                'elements' => []
            ]
        ];

        $response = $this->post(route('print-templates.store'), $payload);

        $response->assertSessionHasErrors(['limit']);
        $this->assertDatabaseMissing('print_templates', ['name' => 'Plantilla Excedente']);
    }

    #[Test]
    public function it_can_upload_an_image_for_template(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('logo.jpg');

        // Act
        $response = $this->post(route('print-templates.media.store'), [
            'image' => $file
        ]);

        // Assert
        $response->assertOk();
        $response->assertJsonStructure(['id', 'url', 'name']);
        
        // Verificar que se guardó en la colección correcta
        $this->assertCount(1, $this->subscription->getMedia('template-images'));
    }

    #[Test]
    public function it_cannot_delete_template_from_another_subscription(): void
    {
        // Arrange: Crear plantilla de OTRA suscripción
        $otherBranch = Branch::factory()->create();
        $otherTemplate = PrintTemplate::factory()->create([
            'subscription_id' => $otherBranch->subscription_id
        ]);

        // Act
        $response = $this->delete(route('print-templates.destroy', $otherTemplate));

        // Assert
        $response->assertForbidden(); // 403
        $this->assertModelExists($otherTemplate);
    }
}