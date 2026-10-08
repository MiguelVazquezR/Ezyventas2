<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Service;
use App\Models\ServiceVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the read endpoints of the mobile API phase 1: the POS catalog and the
 * categories and services used by the service orders module.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private Category $productCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->productCategory = Category::factory()->create([
            'subscription_id' => $this->subscription->id,
            'type' => 'product',
            'name' => 'Refacciones',
        ]);
    }

    #[Test]
    public function it_lists_the_pos_products_of_the_branch(): void
    {
        $owner = $this->ownerUser();

        $product = Product::factory()->withStock($this->branch->id, 24, 4)->create([
            'name' => 'Filtro de aceite',
            'sku' => 'FIL-001',
            'description' => 'Filtro para motor 1.6',
            'selling_price' => 150,
            'category_id' => $this->productCategory->id,
            'price_tiers' => [['min_quantity' => 6, 'price' => 130]],
        ]);

        // Hidden: the product is not for sale in the POS.
        Product::factory()->withStock($this->branch->id, 5)->create(['show_in_pos' => false]);

        // Hidden: the product belongs to another branch.
        $otherBranch = Branch::factory()->create();
        Product::factory()->withStock($otherBranch->id, 5)->create();

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/catalog/products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.name', 'Filtro de aceite')
            ->assertJsonPath('data.0.sku', 'FIL-001')
            ->assertJsonPath('data.0.category', 'Refacciones')
            ->assertJsonPath('data.0.selling_price', '150.00')
            ->assertJsonPath('data.0.price', 150)
            ->assertJsonPath('data.0.original_price', 150)
            ->assertJsonPath('data.0.stock', 20)
            ->assertJsonPath('data.0.reserved_stock', 4)
            ->assertJsonPath('data.0.price_tiers.0.min_quantity', 6)
            ->assertJsonPath('data.0.price_tiers.0.price', 130)
            ->assertJsonPath('data.0.is_bulk', false)
            ->assertJsonPath('data.0.show_in_pos', true)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_filters_products_by_search_and_category(): void
    {
        $owner = $this->ownerUser();
        $otherCategory = Category::factory()->create([
            'subscription_id' => $this->subscription->id,
            'type' => 'product',
            'name' => 'Aceites',
        ]);

        Product::factory()->withStock($this->branch->id)->create([
            'name' => 'Producto Alpha',
            'sku' => 'SKU-001',
            'category_id' => $this->productCategory->id,
        ]);
        Product::factory()->withStock($this->branch->id)->create([
            'name' => 'Producto Beta',
            'sku' => 'SKU-002',
            'category_id' => $otherCategory->id,
        ]);

        $token = $this->tokenFor($owner);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'SKU-001');

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products?search=SKU-002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Producto Beta');

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products?category_id=' . $otherCategory->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Producto Beta');
    }

    #[Test]
    public function it_exposes_the_variants_and_combinations_of_a_product(): void
    {
        $owner = $this->ownerUser();

        $product = Product::factory()->withStock($this->branch->id, 0, 0)->create([
            'name' => 'Playera básica',
            'selling_price' => 150,
        ]);

        $variant = ProductAttribute::create([
            'product_id' => $product->id,
            'attributes' => ['Talla' => 'M'],
            'selling_price_modifier' => 15,
            'sku_suffix' => 'M',
        ]);
        $variant->branches()->attach($this->branch->id, [
            'current_stock' => 5,
            'reserved_stock' => 1,
        ]);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/catalog/products');

        $response->assertOk()
            ->assertJsonPath('data.0.stock', 4)
            ->assertJsonPath('data.0.variants.Talla.0.value', 'M')
            ->assertJsonPath('data.0.variants.Talla.0.stock', 4)
            ->assertJsonPath('data.0.variant_combinations.0.id', $variant->id)
            ->assertJsonPath('data.0.variant_combinations.0.attributes.Talla', 'M')
            ->assertJsonPath('data.0.variant_combinations.0.price_modifier', 15)
            ->assertJsonPath('data.0.variant_combinations.0.price', 165)
            ->assertJsonPath('data.0.variant_combinations.0.stock', 4);
    }

    #[Test]
    public function it_returns_the_product_detail_and_hides_products_of_other_branches(): void
    {
        $owner = $this->ownerUser();
        $product = Product::factory()->withStock($this->branch->id, 10)->create();
        $foreignProduct = Product::factory()->withStock(Branch::factory()->create()->id, 10)->create();

        $token = $this->tokenFor($owner);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products/' . $product->id)
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('stock', 10);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products/' . $foreignProduct->id)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Recurso no encontrado.');
    }

    #[Test]
    public function it_lists_the_categories_of_the_subscription(): void
    {
        $owner = $this->ownerUser();

        Category::factory()->create([
            'subscription_id' => $this->subscription->id,
            'type' => 'service',
            'name' => 'Mantenimiento',
        ]);

        $token = $this->tokenFor($owner);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/categories')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->productCategory->id)
            ->assertJsonPath('0.name', 'Refacciones')
            ->assertJsonPath('0.type', 'product');

        $this->withToken($token)
            ->getJson('/api/v1/catalog/categories?type=service')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Mantenimiento')
            ->assertJsonPath('0.type', 'service');
    }

    #[Test]
    public function it_lists_the_services_with_their_variants(): void
    {
        $owner = $this->ownerUser();

        $serviceCategory = Category::factory()->create([
            'subscription_id' => $this->subscription->id,
            'type' => 'service',
            'name' => 'Reparaciones',
        ]);

        $service = Service::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $serviceCategory->id,
            'name' => 'Cambio de pantalla',
            'base_price' => 850,
            'duration_estimate' => '2 horas',
        ]);
        $service->branches()->attach($this->branch->id);

        ServiceVariant::create([
            'service_id' => $service->id,
            'name' => 'Original',
            'price' => 1450,
        ]);

        // Hidden: the service is not available in this branch.
        Service::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'Servicio de otra sucursal',
        ]);

        $response = $this->withToken($this->tokenFor($owner))
            ->getJson('/api/v1/catalog/services');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $service->id)
            ->assertJsonPath('data.0.name', 'Cambio de pantalla')
            ->assertJsonPath('data.0.base_price', '850.00')
            ->assertJsonPath('data.0.duration_estimate', '2 horas')
            ->assertJsonPath('data.0.category', 'Reparaciones')
            ->assertJsonPath('data.0.variants.0.name', 'Original')
            ->assertJsonPath('data.0.variants.0.price', '1450.00');
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/catalog/products')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    #[Test]
    public function it_requires_the_pos_permission(): void
    {
        $employee = $this->employeeUser(['customers.access']);
        $token = $this->tokenFor($employee);

        $this->withToken($token)
            ->getJson('/api/v1/catalog/products')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');

        $this->withToken($token)
            ->getJson('/api/v1/catalog/categories')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');
    }
}
