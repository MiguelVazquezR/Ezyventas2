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
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia as Assert;

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
        Product::factory()->withStock($this->branch->id)->create([
            'branch_id' => $this->branch->id,
            'name' => 'Producto Alpha',
            'sku' => 'SKU-001'
        ]);
        
        Product::factory()->withStock($this->branch->id)->create([
            'branch_id' => $this->branch->id,
            'name' => 'Producto Beta',
            'sku' => 'SKU-002'
        ]);

        // Prueba búsqueda por nombre
        $response = $this->get(route('products.index', ['search' => 'Alpha']));
        
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
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
            'current_stock' => 50, 
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'provider_id' => $this->provider->id,
            'measure_unit' => 'pz',
            'show_online' => true,
            'branch_ids' => [$this->branch->id], 
            'general_images' => [$image],
        ];

        $response = $this->post(route('products.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        // 1. Verificamos que el producto se creó en BD
        $this->assertDatabaseHas('products', [
            'name' => 'Nuevo Producto Simple',
            'sku' => 'SIMPLE-123',
            // 'slug' fue omitido porque el Action de creación le inyecta un UUID al final para evitar colisiones
            'branch_id' => $this->branch->id,
        ]);

        $product = Product::where('sku', 'SIMPLE-123')->first();
        
        // 2. Verificamos la tabla pivote de stock
        $this->assertDatabaseHas('branch_product', [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'current_stock' => 50
        ]);

        // 3. Verificamos medios
        $this->assertCount(1, $product->getMedia('product-general-images'));
    }

    #[Test]
    public function it_stores_a_variant_product_and_distributes_stock_in_pivot_table(): void
    {
        $payload = [
            'product_type' => 'variant',
            'name' => 'Camiseta Deportiva',
            'sku' => 'TSHIRT-001', 
            'selling_price' => 200.00,
            'category_id' => $this->category->id,
            'measure_unit' => 'pz',
            'branch_ids' => [$this->branch->id], 
            'variants_matrix' => [
                [
                    'selected' => true,
                    'row_id' => 'row_1',
                    'sku' => '-ROJO-S', // OBLIGATORIO: El formRequest espera 'sku', no 'sku_suffix'
                    'current_stock' => 10,
                    'min_stock' => 2,
                    'max_stock' => 100,
                    'selling_price_modifier' => 0.00, // <-- CORRECCIÓN AQUÍ
                    'attributes' => ['Talla' => 'S', 'Color' => 'Rojo'] 
                ],
                [
                    'selected' => true,
                    'row_id' => 'row_2',
                    'sku' => '-AZUL-M', // OBLIGATORIO: El formRequest espera 'sku', no 'sku_suffix'
                    'current_stock' => 15,
                    'min_stock' => 2,
                    'max_stock' => 100,
                    'selling_price_modifier' => 20.00, // <-- CORRECCIÓN AQUÍ
                    'attributes' => ['Talla' => 'M', 'Color' => 'Azul'] 
                ]
                // Se removió el de selected: false ya que simula el filtrado que hace Vue antes de enviar el POST
            ]
        ];

        $response = $this->post(route('products.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Camiseta Deportiva',
        ]);

        $product = Product::where('name', 'Camiseta Deportiva')->first();

        // 2. Verificar Variantes Creadas
        $this->assertCount(2, $product->productAttributes);
        
        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'sku_suffix' => '-ROJO-S',
            'selling_price_modifier' => 0, // 200 - 200
        ]);

        $this->assertDatabaseHas('product_attributes', [
            'product_id' => $product->id,
            'sku_suffix' => '-AZUL-M',
            'selling_price_modifier' => 20, // 220 - 200
        ]);

        // 3. Verificar Stocks en la tabla pivote de las variantes
        $variantRojo = ProductAttribute::where('sku_suffix', '-ROJO-S')->first();
        $variantAzul = ProductAttribute::where('sku_suffix', '-AZUL-M')->first();

        $this->assertDatabaseHas('branch_product_attribute', [
            'product_attribute_id' => $variantRojo->id,
            'branch_id' => $this->branch->id,
            'current_stock' => 10
        ]);

        $this->assertDatabaseHas('branch_product_attribute', [
            'product_attribute_id' => $variantAzul->id,
            'branch_id' => $this->branch->id,
            'current_stock' => 15
        ]);
    }

    #[Test]
    public function it_updates_a_product_and_replaces_variants(): void
    {
        // Arrange: Crear producto padre e inicializar su stock en la tabla pivote
        $product = Product::factory()
            ->withStock($this->branch->id, 10)
            ->create([
                'branch_id' => $this->branch->id,
                'name' => 'Producto Viejo',
                'selling_price' => 100.00,
            ]);
        
        // Crear 1 variante existente
        $variant = $product->productAttributes()->create([
            'attributes' => ['Color' => 'Negro'],
            'sku_suffix' => '-BLK',
            'selling_price_modifier' => 0,
        ]);
        $variant->branches()->attach($this->branch->id, ['current_stock' => 10]);

        // Act: Actualizar a 2 variantes totalmente nuevas
        $payload = [
            'product_type' => 'variant',
            'name' => 'Producto Actualizado',
            'sku' => 'PROD-UPD',
            'selling_price' => 100.00,
            'measure_unit' => 'pz',
            'category_id' => $this->category->id,
            'branch_ids' => [$this->branch->id], 
            'variants_matrix' => [
                [
                    'selected' => true,
                    'row_id' => 'new_1',
                    'sku' => '-WHT', // OBLIGATORIO: El formRequest espera 'sku', no 'sku_suffix'
                    'current_stock' => 20,
                    'min_stock' => 0,
                    'max_stock' => 0,
                    'selling_price_modifier' => 0.00, // <-- CORRECCIÓN AQUÍ
                    'attributes' => ['Color' => 'Blanco'] 
                ],
                [
                    'selected' => true,
                    'row_id' => 'new_2',
                    'sku' => '-RED', // OBLIGATORIO: El formRequest espera 'sku', no 'sku_suffix'
                    'current_stock' => 30,
                    'min_stock' => 0,
                    'max_stock' => 0,
                    'selling_price_modifier' => 0.00, // <-- CORRECCIÓN AQUÍ
                    'attributes' => ['Color' => 'Rojo'] 
                ]
            ]
        ];

        $response = $this->put(route('products.update', $product), $payload);

        // Assert
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('products.index'));

        // 1. Verificar cambio en padre
        $this->assertEquals('Producto Actualizado', $product->fresh()->name);

        // 2. Verificar que la variante vieja se borró
        $this->assertDatabaseMissing('product_attributes', [
            'sku_suffix' => '-BLK',
            'product_id' => $product->id
        ]);

        // 3. Verificar que existen las nuevas
        $this->assertDatabaseHas('product_attributes', ['sku_suffix' => '-WHT']);
        $this->assertDatabaseHas('product_attributes', ['sku_suffix' => '-RED']);
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

        // Crear 1 producto para alcanzar el límite (Añadido withStock para coincidir)
        Product::factory()->withStock($this->branch->id)->create(['branch_id' => $this->branch->id]);

        // Act: Intentar crear otro
        $payload = [
            'product_type' => 'simple',
            'name' => 'Producto Excedente',
            'selling_price' => 100,
            'category_id' => $this->category->id,
            'measure_unit' => 'pz', 
            'current_stock' => 10,
            'branch_ids' => [$this->branch->id] 
        ];

        $response = $this->post(route('products.store'), $payload);

        // Assert
        $response->assertSessionHas('error'); // El controlador responde con un ->with('error') genérico, no como un error de validación
        $this->assertDatabaseMissing('products', ['name' => 'Producto Excedente']);
    }

    #[Test]
    public function it_can_show_product_details_with_relations(): void
    {
        // Arrange
        $product = Product::factory()->withStock($this->branch->id)->create([
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
        $template->branches()->attach($this->branch->id);

        // Act
        $response = $this->get(route('products.show', $product));

        // Assert
        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product/Show')
                ->has('product')
                ->where('product.id', $product->id)
                ->where('product.category.id', $this->category->id)
                ->has('availableTemplates', 1) 
                ->has('activities') 
            );
    }

    #[Test]
    public function it_can_delete_a_product(): void
    {
        // Arrange
        $product = Product::factory()->withStock($this->branch->id)->create([
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
        $products = Product::factory()->count(3)->withStock($this->branch->id)->create([
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

    // --- NUEVAS PRUEBAS PARA COBERTURA AL 100% ---

    #[Test]
    public function it_renders_create_product_page(): void
    {
        $response = $this->get(route('products.create'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product/Create')
                ->has('categories')
                ->has('brands')
                ->has('providers')
            );
    }

    #[Test]
    public function it_renders_edit_product_page(): void
    {
        $product = Product::factory()->withStock($this->branch->id)->create(['branch_id' => $this->branch->id]);

        $response = $this->get(route('products.edit', $product));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Product/Edit')
                ->has('product')
                ->where('product.id', $product->id)
            );
    }

    #[Test]
    public function it_can_bulk_update_products_and_variants(): void
    {
        // Arrange: Crear producto simple
        $product = Product::factory()->withStock($this->branch->id, 10, 0, 0, 50)->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 100.00,
            'show_in_pos' => true
        ]);

        $payload = [
            'items' => [
                [
                    'type' => 'product',
                    'id' => $product->id,
                    'selling_price' => 150.00,
                    'show_in_pos' => false,
                    'min_stock' => 10,
                ]
            ]
        ];

        // Act
        $response = $this->post(route('products.bulkUpdate'), $payload);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals(150.00, $product->fresh()->selling_price);
        $this->assertFalse((bool) $product->fresh()->show_in_pos);

        // Validar el cambio en la tabla pivote
        $pivot = \Illuminate\Support\Facades\DB::table('branch_product')
            ->where('branch_id', $this->branch->id)
            ->where('product_id', $product->id)
            ->first();
        
        $this->assertEquals(10, $pivot->min_stock);
    }

    #[Test]
    public function it_can_update_price_directly_from_pos(): void
    {
        $product = Product::factory()->withStock($this->branch->id)->create([
            'branch_id' => $this->branch->id,
            'selling_price' => 50.00
        ]);

        // CORRECCIÓN 8: Usar el nombre de ruta correcto que utiliza el archivo de rutas (products.update-price-pos)
        $response = $this->postJson(route('products.update-price-pos'), [
            'product_id' => $product->id,
            'new_price' => 85.00
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(85.00, $product->fresh()->selling_price);
    }

    #[Test]
    public function it_denies_access_without_permissions(): void
    {
        // Eliminar permisos
        $this->user->roles()->detach();

        // El middleware debe interceptarlo
        $response = $this->get(route('products.index'));
        
        $response->assertForbidden(); 
    }
}