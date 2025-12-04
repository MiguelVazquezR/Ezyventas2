<?php

namespace Tests\Feature;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Provider;
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

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Branch $branch;
    private Category $category;
    private Brand $brand;
    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Configuración Base
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $subscription = $this->branch->subscription;

        $subscription->update(['onboarding_completed_at' => now()]);

        // 2. Suscripción Activa
        $version = SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        // 3. Permisos
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        
        $permissions = [
            'products.access',
            'products.create',
            'products.see_details',
            'products.edit',
            'products.delete',
        ];

        foreach ($permissions as $p) {
            Permission::create(['name' => $p, 'module' => 'products']);
        }

        $role = Role::create(['name' => 'Administrador', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo($permissions);
        $this->user->assignRole($role);

        // 4. Datos Auxiliares
        $this->category = Category::factory()->create([
            'type' => 'product',
            'subscription_id' => $subscription->id
        ]);
        
        $this->brand = Brand::factory()->create(['subscription_id' => $subscription->id]);
        $this->provider = Provider::factory()->create(['subscription_id' => $subscription->id]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_products_with_filters(): void
    {
        Product::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Producto Alpha',
            'sku' => 'SKU-001'
        ]);
        
        Product::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Producto Beta',
            'sku' => 'SKU-002'
        ]);

        // Prueba búsqueda por nombre
        $response = $this->get(route('products.index', ['search' => 'Alpha']));
        
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Product/Index')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Producto Alpha')
            );
    }

    #[Test]
    public function it_stores_a_simple_product_successfully(): void
    {
        Storage::fake('public');
        $image = UploadedFile::fake()->image('product.jpg');

        $payload = [
            'product_type' => 'simple',
            'name' => 'Nuevo Producto Simple',
            'sku' => 'SIMPLE-123',
            'barcode' => '123456789',
            'description' => 'Descripción del producto',
            'selling_price' => 150.00,
            'cost_price' => 100.00,
            'min_stock' => 5,
            'current_stock' => 50, // Stock directo para simple
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'provider_id' => $this->provider->id,
            'measure_unit' => 'pz',
            'show_online' => true,
            'general_images' => [$image],
        ];

        $response = $this->post(route('products.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Nuevo Producto Simple',
            'sku' => 'SIMPLE-123',
            'current_stock' => 50,
            'slug' => 'nuevo-producto-simple', // Verifica generación de slug
            'branch_id' => $this->branch->id,
        ]);

        $product = Product::where('sku', 'SIMPLE-123')->first();
        $this->assertCount(1, $product->getMedia('product-general-images'));
    }

    #[Test]
    public function it_stores_a_variant_product_and_calculates_total_stock(): void
    {
        $payload = [
            'product_type' => 'variant',
            'name' => 'Camiseta Deportiva',
            'sku' => 'TSHIRT-001', // SKU base
            'selling_price' => 200.00,
            'category_id' => $this->category->id,
            'measure_unit' => 'pz',
            'variants_matrix' => [
                [
                    'selected' => true,
                    'row_id' => 'row_1',
                    'sku_suffix' => '-ROJO-S',
                    'current_stock' => 10,
                    'min_stock' => 2,
                    'max_stock' => 100,
                    'selling_price' => 200.00, // Mismo precio
                    'Talla' => 'S',
                    'Color' => 'Rojo'
                ],
                [
                    'selected' => true,
                    'row_id' => 'row_2',
                    'sku_suffix' => '-AZUL-M',
                    'current_stock' => 15,
                    'min_stock' => 2,
                    'max_stock' => 100,
                    'selling_price' => 220.00, // Precio diferente (+20)
                    'Talla' => 'M',
                    'Color' => 'Azul'
                ],
                [
                    'selected' => false, // Esta no se debe guardar
                    'row_id' => 'row_3',
                    'sku_suffix' => '-VERDE-L',
                    'current_stock' => 5,
                    'Talla' => 'L',
                    'Color' => 'Verde'
                ]
            ]
        ];

        $response = $this->post(route('products.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        // 1. Verificar Producto Padre
        $this->assertDatabaseHas('products', [
            'name' => 'Camiseta Deportiva',
            'current_stock' => 25, // 10 + 15 (suma de variantes seleccionadas)
        ]);

        $product = Product::where('name', 'Camiseta Deportiva')->first();

        // 2. Verificar Variantes Creadas
        $this->assertCount(2, $product->productAttributes);
        
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'sku_suffix' => '-ROJO-S',
            'current_stock' => 10,
            'selling_price_modifier' => 0, // 200 - 200
        ]);

        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'sku_suffix' => '-AZUL-M',
            'current_stock' => 15,
            'selling_price_modifier' => 20, // 220 - 200
        ]);
    }

    #[Test]
    public function it_updates_a_product_and_replaces_variants(): void
    {
        // Arrange: Crear producto con 1 variante
        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Producto Viejo',
            'selling_price' => 100.00,
            'current_stock' => 10,
        ]);
        
        $product->productAttributes()->create([
            'attributes' => ['Color' => 'Negro'],
            'current_stock' => 10,
            'sku_suffix' => '-BLK'
        ]);

        // Act: Actualizar a 2 variantes totalmente nuevas
        $payload = [
            'product_type' => 'variant',
            'name' => 'Producto Actualizado',
            'sku' => 'PROD-UPD',
            'selling_price' => 100.00,
            'measure_unit' => 'pz',
            'category_id' => $this->category->id,
            'variants_matrix' => [
                [
                    'selected' => true,
                    'row_id' => 'new_1',
                    'sku_suffix' => '-WHT',
                    'current_stock' => 20,
                    'min_stock' => 0,
                    'max_stock' => 0,
                    'selling_price' => 100.00,
                    'Color' => 'Blanco'
                ],
                [
                    'selected' => true,
                    'row_id' => 'new_2',
                    'sku_suffix' => '-RED',
                    'current_stock' => 30,
                    'min_stock' => 0,
                    'max_stock' => 0,
                    'selling_price' => 100.00,
                    'Color' => 'Rojo'
                ]
            ]
        ];

        $response = $this->put(route('products.update', $product), $payload);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        // 1. Verificar cambio en padre
        $this->assertEquals('Producto Actualizado', $product->fresh()->name);
        $this->assertEquals(50, $product->fresh()->current_stock); // 20 + 30

        // 2. Verificar que la variante vieja se borró
        $this->assertDatabaseMissing('product_attributes', [
            'sku_suffix' => '-BLK',
            'product_id' => $product->id
        ]);

        // 3. Verificar que existen las nuevas
        $this->assertDatabaseHas('product_attributes', [
            'sku_suffix' => '-WHT',
            'current_stock' => 20
        ]);
    }

    #[Test]
    public function it_prevents_creating_product_if_subscription_limit_reached(): void
    {
        // Arrange: Configurar límite en la suscripción
        $version = $this->branch->subscription->versions()->latest('start_date')->first();
        
        $version->items()->create([
            'item_key' => 'limit_products',
            'item_type' => 'limit',
            'name' => 'Límite de productos',
            'quantity' => 1, // Límite de 1 producto
            'unit_price' => 0,
            'price' => 0
        ]);

        // Crear 1 producto para alcanzar el límite
        Product::factory()->create(['branch_id' => $this->branch->id]);

        // Act: Intentar crear otro
        $payload = [
            'product_type' => 'simple',
            'name' => 'Producto Excedente',
            'selling_price' => 100,
            'category_id' => $this->category->id,
            // CORRECCIÓN 1: Agregar campos obligatorios para pasar validación de FormRequest
            'measure_unit' => 'pz', 
            'current_stock' => 10 
        ];

        $response = $this->post(route('products.store'), $payload);

        // Assert
        $response->assertSessionHasErrors(['limit']);
        $this->assertDatabaseMissing('products', ['name' => 'Producto Excedente']);
    }

    #[Test]
    public function it_can_show_product_details_with_relations(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id
        ]);

        // Crear plantillas de impresión
        $template = PrintTemplate::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
            'type' => TemplateType::LABEL->value,
            'context_type' => TemplateContextType::PRODUCT->value
        ]);
        // CORRECCIÓN 2: Vincular la plantilla a la sucursal actual
        // El controlador usa Auth::user()->branch->printTemplates(), así que la relación es necesaria
        $template->branches()->attach($this->branch->id);

        // Act
        $response = $this->get(route('products.show', $product));

        // Assert
        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Product/Show')
                ->has('product')
                ->where('product.id', $product->id)
                ->where('product.category.id', $this->category->id)
                ->has('availableTemplates', 1) // Ahora debería tener 1
                ->has('activities') 
            );
    }

    #[Test]
    public function it_can_delete_a_product(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'branch_id' => $this->branch->id
        ]);

        // Act
        $response = $this->delete(route('products.destroy', $product));

        // Assert
        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    #[Test]
    public function it_can_bulk_delete_products(): void
    {
        // Arrange
        $products = Product::factory()->count(3)->create([
            'branch_id' => $this->branch->id
        ]);
        $ids = $products->pluck('id')->toArray();

        // Act
        $response = $this->post(route('products.batchDestroy'), ['ids' => $ids]);

        // Assert
        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('success');
        
        foreach ($ids as $id) {
            $this->assertDatabaseMissing('products', ['id' => $id]);
        }
    }
}