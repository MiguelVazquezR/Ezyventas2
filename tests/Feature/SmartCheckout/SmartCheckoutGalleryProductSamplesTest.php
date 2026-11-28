<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\Product;
use App\Models\SmartPosProductSample;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Muestras guardadas de un producto (Fase 4).
 *
 * La UI del modo lista las muestras de un producto para que el cajero las revise
 * y, si quiere, las borre con el `DELETE /samples/{id}` de la Fase 3. La respuesta
 * nunca incluye el vector (son 1 536 bytes por muestra: se quedan en la galería).
 * Estas pruebas cubren:
 *
 *  - el gate del negocio (403 con el modo apagado),
 *  - `source` / `quality` / `dim` / `has_crop` y el orden (más recientes primero),
 *  - el filtro por `model_version` y el caso sin filtro (todas las versiones),
 *  - 404 en español con un producto de otra sucursal o inexistente (nunca 403),
 *  - aislamiento: ni las muestras de otra suscripción ni las de otro producto.
 */
class SmartCheckoutGalleryProductSamplesTest extends SmartCheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Storage::fake('public');
    }

    /*
    |--------------------------------------------------------------------------
    | PUERTA DEL MÓDULO Y CONTRATO DE LA RUTA
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_403_while_the_mode_is_disabled(): void
    {
        $product = $this->makeProduct();

        $this->getJson(route('pos.smart-checkout.gallery.product', $product))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');
    }

    #[Test]
    public function the_product_route_requires_the_pos_permission_and_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('pos.smart-checkout.gallery.product');

        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertSame('pos/smart-checkout/gallery/products/{product}', $route->uri());
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
        $this->assertContains('can:pos.access', $route->gatherMiddleware());
    }

    /*
    |--------------------------------------------------------------------------
    | LISTADO
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_the_samples_of_the_product_newest_first(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $first = $this->addSample($product, 'mock', 0.91);
        $this->travel(2)->minutes();
        $second = $this->addSample($product, 'mock', null);
        $this->travel(2)->minutes();
        $third = $this->addSample(
            $product,
            'mock',
            0.50,
            'smartpos/crops/'.$this->branch->subscription_id.'/'.$product->id.'/recorte.jpg',
        );

        $response = $this->getJson(route('pos.smart-checkout.gallery.product', $product))
            ->assertOk()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('name', $product->name)
            ->assertJsonPath('sku', $product->sku)
            ->assertJsonPath('count', 3);

        $samples = $response->json('samples');

        // Más recientes primero.
        $this->assertSame(
            [$third->id, $second->id, $first->id],
            array_column($samples, 'id'),
        );

        $this->assertSame(SmartPosProductSample::SOURCE_CATALOG_IMAGE, $samples[0]['source']);
        $this->assertSame(0.5, $samples[0]['quality']);
        $this->assertSame(384, $samples[0]['dim']);
        $this->assertSame('mock', $samples[0]['model_version']);
        $this->assertTrue($samples[0]['has_crop']);

        // Una muestra sin calidad se expone como null (no como 0).
        $this->assertNull($samples[1]['quality']);
        $this->assertFalse($samples[1]['has_crop']);

        $this->assertSame(0.91, $samples[2]['quality']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            (string) $samples[0]['created_at'],
        );

        // El vector nunca viaja en esta respuesta.
        $this->assertArrayNotHasKey('embedding', $samples[0]);
        $this->assertArrayNotHasKey('vector', $samples[0]);
    }

    #[Test]
    public function it_serves_every_model_version_when_none_is_requested(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $mock = $this->addSample($product, 'mock', 0.9);
        $this->travel(1)->minutes();
        $dinov2 = $this->addSample($product, 'dinov2-small-onnx-2026-11', 0.8);

        $response = $this->getJson(route('pos.smart-checkout.gallery.product', $product))
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->assertSame(
            [$dinov2->id, $mock->id],
            array_column($response->json('samples'), 'id'),
        );
    }

    #[Test]
    public function it_filters_the_samples_by_model_version(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->addSample($product, 'mock', 0.9);
        $this->addSample($product, 'dinov2-small-onnx-2026-11', 0.8);

        $filtered = $this->getJson(route('pos.smart-checkout.gallery.product', [
            'product' => $product,
            'model_version' => 'dinov2-small-onnx-2026-11',
        ]))->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('samples.0.model_version', 'dinov2-small-onnx-2026-11');

        $this->assertSame(1, count($filtered->json('samples')));

        $this->getJson(route('pos.smart-checkout.gallery.product', [
            'product' => $product,
            'model_version' => 'otro-modelo',
        ]))->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('samples', []);
    }

    #[Test]
    public function it_rejects_a_model_version_that_is_not_text_or_too_long(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->getJson(route('pos.smart-checkout.gallery.product', [
            'product' => $product,
            'model_version' => str_repeat('a', 65),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('model_version');
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUCTO AJENO O INEXISTENTE
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_404_for_a_product_of_another_branch_or_a_missing_one(): void
    {
        $this->enableSmartCheckout();

        // Misma suscripción, otra sucursal: el cajero no debe saber que existe.
        $siblingBranch = Branch::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);

        $foreignProduct = Product::factory()
            ->withStock($siblingBranch->id)
            ->create(['branch_id' => $siblingBranch->id, 'show_in_pos' => true]);

        $this->getJson(route('pos.smart-checkout.gallery.product', $foreignProduct))
            ->assertNotFound()
            ->assertJsonPath('message', 'El producto indicado no existe en la sucursal del cajero.');

        // Y tampoco confirma la existencia de un id de otro negocio.
        $otherBranch = $this->makeTenant();
        $otherProduct = Product::factory()
            ->withStock($otherBranch->id)
            ->create(['branch_id' => $otherBranch->id]);

        $this->getJson(route('pos.smart-checkout.gallery.product', $otherProduct))
            ->assertNotFound();

        $this->getJson(route('pos.smart-checkout.gallery.product', 999999))
            ->assertNotFound()
            ->assertJsonPath('message', 'El producto indicado no existe en la sucursal del cajero.');
    }

    /*
    |--------------------------------------------------------------------------
    | AISLAMIENTO ENTRE NEGOCIOS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_never_serves_samples_of_another_subscription(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $own = $this->addSample($product, 'mock', 0.9);

        // Otro negocio con una muestra que apunta al mismo product_id.
        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);

        SmartPosProductSample::create([
            'subscription_id' => $otherBranch->subscription_id,
            'branch_id' => $otherBranch->id,
            'product_id' => $product->id,
            'model_version' => 'mock',
            'embedding' => $this->unitEmbedding(),
            'dim' => 384,
            'source' => SmartPosProductSample::SOURCE_BARCODE_SCAN,
            'quality' => 0.10,
        ]);

        $response = $this->getJson(route('pos.smart-checkout.gallery.product', $product))
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->assertSame([$own->id], array_column($response->json('samples'), 'id'));

        // Y el otro negocio no ve el producto (no es de su sucursal).
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherUser)
            ->getJson(route('pos.smart-checkout.gallery.product', $product))
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Producto de la sucursal del cajero, vinculado a su `branch_product`.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = [], ?Branch $branch = null): Product
    {
        $branch ??= $this->branch;

        return Product::factory()
            ->withStock($branch->id)
            ->create(array_merge([
                'branch_id' => $branch->id,
                'show_in_pos' => true,
            ], $overrides));
    }

    private function addSample(
        Product $product,
        string $modelVersion = 'mock',
        ?float $quality = 0.9,
        ?string $cropPath = null,
    ): SmartPosProductSample {
        return SmartPosProductSample::create([
            'subscription_id' => $this->branch->subscription_id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'model_version' => $modelVersion,
            'embedding' => $this->unitEmbedding(),
            'dim' => 384,
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
            'crop_path' => $cropPath,
            'quality' => $quality,
        ]);
    }

    /**
     * Vector unitario empaquetado como float32 little-endian (el formato de la galería).
     *
     * El `...` no es decorativo: sin él `pack()` recibe **un solo argumento** (el
     * array) y empaqueta 4 bytes en vez de 1536.
     */
    private function unitEmbedding(): string
    {
        return pack('g*', ...array_fill(0, 384, 1 / sqrt(384)));
    }
}
