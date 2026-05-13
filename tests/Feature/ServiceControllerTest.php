<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Service;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

class ServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Configuración Base (Sucursal, Usuario, Suscripción)
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;

        $subscription->update(['onboarding_completed_at' => now()]);

        // 2. Suscripción Activa
        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Permisos (Spatie)
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        $permissions = [
            'services.catalog.access',
            'services.catalog.create',
            'services.catalog.see_details',
            'services.catalog.edit',
            'services.catalog.delete',
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'services']);
        }

        $role = Role::create(['name' => 'Admin Servicios', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Datos Auxiliares (Categoría de tipo servicio)
        $this->category = Category::factory()->create([
            'type' => 'service',
            'subscription_id' => $subscription->id,
            'name' => 'Mantenimiento'
        ]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_services_and_filter_by_name(): void
    {
        // Arrange
        $serviceA = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'name' => 'Servicio A - Limpieza'
        ]);
        $serviceA->branches()->attach($this->branch->id); // Vincular a tabla pivote
        
        $serviceB = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'name' => 'Servicio B - Reparación'
        ]);
        $serviceB->branches()->attach($this->branch->id); // Vincular a tabla pivote

        // Act: Filtrar por "Limpieza"
        $response = $this->get(route('services.index', ['search' => 'Limpieza']));

        // Assert
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Service/Index')
                ->has('services.data', 1)
                ->where('services.data.0.name', 'Servicio A - Limpieza')
            );
    }

    #[Test]
    public function it_stores_a_service_successfully_with_image(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('service.jpg');

        $payload = [
            'name' => 'Instalación de Software',
            'description' => 'Instalación de Office y Antivirus',
            'category_id' => $this->category->id,
            'base_price' => 350.50,
            'duration_estimate' => '1 hora',
            'show_online' => true,
            'branch_ids' => [$this->branch->id], // Necesario para la Action
            'image' => $image
        ];

        $response = $this->post(route('services.store'), $payload);

        $response->assertRedirect(route('services.index'));
        $response->assertSessionHas('success');

        // 1. Verificar Base de Datos
        $this->assertDatabaseHas('services', [
            'name' => 'Instalación de Software',
            'base_price' => 350.50,
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id
        ]);

        $service = Service::where('name', 'Instalación de Software')->first();
        
        // 2. Verificar que el ServiceAction lo insertó en el pivote
        $this->assertTrue($service->branches()->where('branch_id', $this->branch->id)->exists());

        // 3. Verificar Imagen
        $this->assertCount(1, $service->getMedia('service-image'));
    }

    #[Test]
    public function it_validates_required_fields_when_storing_service(): void
    {
        $response = $this->post(route('services.store'), []);

        // CORRECCIÓN: 'base_price' es nullable según tu Request.
        // Los que sí fallarán por no enviarse son name, category_id y branch_ids.
        $response->assertSessionHasErrors([
            'name', 
            'category_id', 
            'branch_ids'
        ]);
    }

    #[Test]
    public function it_updates_a_service_and_regenerates_slug_if_name_changes(): void
    {
        // Arrange
        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'name' => 'Nombre Antiguo',
            'base_price' => 100
        ]);
        $service->branches()->attach($this->branch->id);

        $payload = [
            'name' => 'Nombre Nuevo',
            'description' => 'Descripción actualizada',
            'category_id' => $this->category->id,
            'base_price' => 150.00,
            'show_online' => false,
            'branch_ids' => [$this->branch->id]
        ];

        // Act
        $response = $this->put(route('services.update', $service), $payload);

        // Assert
        $response->assertRedirect(route('services.index'));
        
        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'name' => 'Nombre Nuevo',
            'slug' => 'nombre-nuevo', // El slug debió cambiar
            'base_price' => 150.00
        ]);
    }

    #[Test]
    public function it_shows_service_details_with_activity_log(): void
    {
        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id
        ]);
        $service->branches()->attach($this->branch->id);

        $response = $this->get(route('services.show', $service));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Service/Show')
                ->has('service')
                ->where('service.id', $service->id)
                ->has('activities') // Verifica que se cargue el historial
            );
    }

    #[Test]
    public function it_can_delete_a_service(): void
    {
        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id
        ]);

        $response = $this->delete(route('services.destroy', $service));

        $response->assertRedirect(route('services.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    #[Test]
    public function it_can_bulk_delete_services(): void
    {
        $services = Service::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id
        ]);
        $ids = $services->pluck('id')->toArray();

        $response = $this->post(route('services.batchDestroy'), ['ids' => $ids]);

        $response->assertRedirect(route('services.index'));
        
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('services', ['id' => $id]);
        }
    }

    // --- NUEVAS PRUEBAS AÑADIDAS PARA COBERTURA AL 100% ---

    #[Test]
    public function it_renders_create_service_page(): void
    {
        $response = $this->get(route('services.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Service/Create')
                ->has('categories')
                ->has('branches')
                ->where('serviceLimitReached', false)
            );
    }

    #[Test]
    public function it_renders_edit_service_page(): void
    {
        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id
        ]);
        $service->branches()->attach($this->branch->id);

        $response = $this->get(route('services.edit', $service));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Service/Edit')
                ->has('service')
                ->where('service.id', $service->id)
                ->has('categories')
                ->has('branches')
            );
    }

    #[Test]
    public function it_prevents_creating_service_if_subscription_limit_reached(): void
    {
        // Arrange: Configurar límite de 1 en la suscripción
        $version = $this->branch->subscription->versions()->latest('start_date')->first();
        
        $version->items()->create([
            'item_key' => 'limit_services',
            'item_type' => 'limit',
            'name' => 'Límite de servicios',
            'quantity' => 1,
            'unit_price' => 0,
            'price' => 0
        ]);

        // Crear 1 servicio para agotar el límite
        $service = Service::factory()->create(['branch_id' => $this->branch->id]);
        $service->branches()->attach($this->branch->id);

        // Act: Intentar crear un segundo servicio
        $payload = [
            'name' => 'Servicio Excedente',
            'description' => 'Excede el limite',
            'category_id' => $this->category->id,
            'base_price' => 100,
            'branch_ids' => [$this->branch->id]
        ];

        $response = $this->post(route('services.store'), $payload);

        // Assert
        $response->assertSessionHas('error'); // Flash message lanzado por el Controller
        $this->assertDatabaseMissing('services', ['name' => 'Servicio Excedente']);
    }

    #[Test]
    public function it_denies_access_without_permissions(): void
    {
        // Quitamos los permisos
        $this->user->roles()->detach();

        // El middleware (HasMiddleware) debe bloquear la vista
        $response = $this->get(route('services.index'));
        
        $response->assertForbidden(); 
    }
}