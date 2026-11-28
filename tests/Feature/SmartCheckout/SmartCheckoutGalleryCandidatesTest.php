<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\SmartPosProductSample;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutCatalogGalleryService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Candidatos de la galería desde el catálogo (Fase 4).
 *
 * El endpoint sirve los productos de la sucursal que se pueden sembrar en la
 * galería de vectores junto con sus imágenes, paginados por keyset, para que el
 * navegador descargue cada foto, calcule su embedding con DINOv2 y lo suba con el
 * `POST /samples` existente. Estas pruebas cubren:
 *
 *  - el gate del negocio (403 con el modo apagado),
 *  - sólo productos de la sucursal, visibles en el POS y con foto real,
 *  - una imagen principal cuando el producto no tiene variantes,
 *  - una imagen por variante (sin la general) cuando las fotos de variante emparejan,
 *  - caída a la principal cuando hay variantes pero no fotos de variante,
 *  - `samples` / `pending` por versión del modelo y los filtros `pending` / `all`,
 *  - paginación keyset sin solapamiento,
 *  - validación 422 en español,
 *  - aislamiento entre negocios (ni productos ni contadores).
 *
 * El throttle se desactiva: el limitador va por usuario y cada prueba recrea los
 * mismos ids en un solo proceso PHP, así que el presupuesto de 120 por minuto se
 * filtraría entre pruebas. `the_candidates_route_requires_the_pos_permission_and_is_throttled`
 * comprueba que el middleware sí está enganchado.
 */
class SmartCheckoutGalleryCandidatesTest extends SmartCheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        // Medios herméticos: nada se escribe en storage/app/public durante las pruebas.
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
        $this->makeProductWithImage('coca.jpg');

        $this->getJson(route('pos.smart-checkout.gallery.candidates'))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        $this->getJson(route('pos.smart-checkout.gallery.product', $this->makeProduct()))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');
    }

    #[Test]
    public function the_candidates_route_requires_the_pos_permission_and_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('pos.smart-checkout.gallery.candidates');

        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods());
        $this->assertSame('pos/smart-checkout/gallery/candidates', $route->uri());
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
        // Sin permisos propios del módulo: el del POS es el único gate (decisiones.md).
        $this->assertContains('can:pos.access', $route->gatherMiddleware());
    }

    /*
    |--------------------------------------------------------------------------
    | SELECCIÓN DE PRODUCTOS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_lists_only_branch_products_visible_in_the_pos_with_real_media(): void
    {
        $this->enableSmartCheckout();

        $visible = $this->makeProductWithImage('visible.jpg');

        // Sin ninguna foto: no es seleccionable (el placeholder del catálogo no cuenta).
        $withoutMedia = $this->makeProduct();

        $hidden = $this->makeProductWithImage('oculto.jpg', [
            'show_in_pos' => false,
        ]);

        // Misma suscripción, otra sucursal: el POS de este cajero no lo vende.
        $siblingBranch = Branch::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);
        $foreign = $this->makeProductWithImage('ajeno.jpg', [], $siblingBranch);

        $response = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk();

        $this->assertSame([$visible->id], $this->productIds($response->json('items')));
        $this->assertSame(1, $response->json('count'));

        foreach ([$withoutMedia->id, $hidden->id, $foreign->id] as $id) {
            $this->assertNotContains($id, $this->productIds($response->json('items')));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | IMÁGENES
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_serves_a_single_general_image_for_a_product_without_variants(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->addImage($product, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, 'primera.jpg');
        $this->addImage($product, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, 'segunda.jpg');

        $response = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk();

        $images = $response->json('items.0.images');

        $this->assertCount(1, $images);
        $this->assertSame('general', $images[0]['kind']);
        $this->assertNull($images[0]['label']);
        $this->assertNull($images[0]['variant_id']);
        $this->assertStringContainsString('primera.jpg', $images[0]['url']);
        $this->assertStringNotContainsString('segunda.jpg', $images[0]['url']);
    }

    #[Test]
    public function it_serves_one_image_per_variant_and_skips_the_general_one(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->addImage($product, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, 'general.jpg');

        $rojo = $this->makeVariant($product, ['Color' => 'Rojo']);
        $azul = $this->makeVariant($product, ['Color' => 'Azul']);

        $this->addImage(
            $product,
            SmartCheckoutCatalogGalleryService::VARIANT_COLLECTION,
            'rojo.jpg',
            ['variant_key' => 'Color_Rojo', 'variant_option' => 'Rojo'],
        );
        $this->addImage(
            $product,
            SmartCheckoutCatalogGalleryService::VARIANT_COLLECTION,
            'azul.jpg',
            ['variant_key' => 'Color_Azul', 'variant_option' => 'Azul'],
        );

        $images = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk()->json('items.0.images');

        $this->assertCount(2, $images);

        $this->assertSame(['variant', 'variant'], array_column($images, 'kind'));
        $this->assertSame(['Rojo', 'Azul'], array_column($images, 'label'));
        $this->assertSame([$rojo->id, $azul->id], array_column($images, 'variant_id'));

        // La imagen general no se incluye cuando se usan las de variante.
        foreach ($images as $image) {
            $this->assertStringNotContainsString('general.jpg', $image['url']);
        }
    }

    #[Test]
    public function it_falls_back_to_the_main_image_when_the_variants_have_no_variant_images(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->makeVariant($product, ['Color' => 'Rojo']);
        $this->makeVariant($product, ['Color' => 'Azul', 'Talla' => 'M']);

        $this->addImage($product, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, 'principal.jpg');

        $images = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk()->json('items.0.images');

        $this->assertCount(1, $images);
        $this->assertSame('general', $images[0]['kind']);
        $this->assertNull($images[0]['label']);
        $this->assertStringContainsString('principal.jpg', $images[0]['url']);
    }

    /*
    |--------------------------------------------------------------------------
    | MUESTRAS Y PENDING
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_excludes_seeded_products_with_filter_pending_and_includes_them_with_filter_all(): void
    {
        $this->enableSmartCheckout();

        $pending = $this->makeProductWithImage('pendiente.jpg');

        $seeded = $this->makeProductWithImage('sembrado.jpg');
        $this->addSample($seeded);

        // Una muestra de otro modelo no siembra la galería del modelo actual.
        $seeded->refresh();
        $this->addSample($seeded, 'otro-modelo');

        $onlyPending = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'pending',
        ]))->assertOk();

        $this->assertSame([$pending->id], $this->productIds($onlyPending->json('items')));

        $all = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk();

        $items = $all->json('items');

        $this->assertSame([$pending->id, $seeded->id], $this->productIds($items));

        $this->assertSame(0, $items[0]['samples']);
        $this->assertSame(1, $items[0]['pending']);
        $this->assertSame(1, $items[1]['samples']);
        $this->assertSame(0, $items[1]['pending']);

        // El contador de la UI no depende del filtro ni de la página.
        $this->assertSame(1, $all->json('total_pending'));
        $this->assertSame(1, $onlyPending->json('total_pending'));
    }

    #[Test]
    public function it_counts_the_missing_samples_of_the_whole_branch_in_one_aggregate(): void
    {
        $this->enableSmartCheckout();

        $seeded = $this->makeProductWithImage('sembrado.jpg');
        $this->addSample($seeded);

        $this->makeProductWithImage('pendiente-1.jpg');
        $this->makeProductWithImage('pendiente-2.jpg');

        // Sin foto: nunca es candidato, así que tampoco suma al contador.
        $this->makeProduct();

        // Producto con dos fotos de variante: le faltan las dos.
        $withVariants = $this->makeProduct();
        $this->makeVariant($withVariants, ['Color' => 'Rojo']);
        $this->makeVariant($withVariants, ['Color' => 'Azul']);
        $this->addImage(
            $withVariants,
            SmartCheckoutCatalogGalleryService::VARIANT_COLLECTION,
            'rojo.jpg',
            ['variant_key' => 'Color_Rojo'],
        );
        $this->addImage(
            $withVariants,
            SmartCheckoutCatalogGalleryService::VARIANT_COLLECTION,
            'azul.jpg',
            ['variant_key' => 'Color_Azul'],
        );

        $response = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
            'limit' => 50,
        ]))->assertOk();

        // Tres productos pendientes (los dos de una foto y el de dos) más el
        // sembrado, que no cuenta.
        $this->assertSame(3, $response->json('total_pending'));
        $this->assertSame(4, $response->json('count'));
        // La versión por defecto es la de los ajustes del negocio.
        $this->assertSame('mock', $response->json('model_version'));
    }

    /*
    |--------------------------------------------------------------------------
    | PAGINACIÓN KEYSET
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_paginates_with_a_keyset_cursor_without_overlapping_pages(): void
    {
        $this->enableSmartCheckout();

        $products = [];

        for ($index = 1; $index <= 5; $index++) {
            $products[] = $this->makeProductWithImage("foto-{$index}.jpg");
        }

        $first = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'limit' => 2,
        ]))->assertOk();

        $firstIds = $this->productIds($first->json('items'));

        $this->assertCount(2, $firstIds);
        $this->assertSame([$products[0]->id, $products[1]->id], $firstIds);
        $this->assertTrue($first->json('has_more'));
        $this->assertSame($products[1]->id, $first->json('next_cursor'));

        $second = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'limit' => 2,
            'after_id' => $first->json('next_cursor'),
        ]))->assertOk();

        $secondIds = $this->productIds($second->json('items'));

        $this->assertSame([$products[2]->id, $products[3]->id], $secondIds);
        $this->assertTrue($second->json('has_more'));
        $this->assertEmpty(array_intersect($firstIds, $secondIds));

        $third = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'limit' => 2,
            'after_id' => $second->json('next_cursor'),
        ]))->assertOk();

        $thirdIds = $this->productIds($third->json('items'));

        $this->assertSame([$products[4]->id], $thirdIds);
        $this->assertFalse($third->json('has_more'));
        $this->assertNull($third->json('next_cursor'));
        $this->assertEmpty(array_intersect($secondIds, $thirdIds));
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIÓN
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_limits_above_fifty_and_invalid_cursors(): void
    {
        $this->enableSmartCheckout();

        $invalid = [
            'limit above the maximum' => [['limit' => 51], 'limit'],
            'limit of zero' => [['limit' => 0], 'limit'],
            'limit not an integer' => [['limit' => 'muchos'], 'limit'],
            'cursor of zero' => [['after_id' => 0], 'after_id'],
            'cursor not an integer' => [['after_id' => 'inicio'], 'after_id'],
            'unknown filter' => [['filter' => 'sembrados'], 'filter'],
            'model version too long' => [['model_version' => str_repeat('a', 65)], 'model_version'],
        ];

        foreach ($invalid as $case => [$query, $errorKey]) {
            $response = $this->getJson(route('pos.smart-checkout.gallery.candidates', $query))
                ->assertStatus(422);

            $response->assertJsonValidationErrors($errorKey);

            // El mensaje que llega al cliente es español, no el del framework.
            $this->assertNotSame('', (string) $response->json('message'), $case);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AISLAMIENTO ENTRE NEGOCIOS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_does_not_leak_products_samples_or_counts_between_tenants(): void
    {
        $this->enableSmartCheckout();

        $ownProduct = $this->makeProductWithImage('propio.jpg');

        // Segundo negocio, con el modo activo, un producto con foto y una muestra.
        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherUser);

        $otherProduct = Product::factory()
            ->withStock($otherBranch->id)
            ->create(['branch_id' => $otherBranch->id, 'show_in_pos' => true]);

        $this->addImage($otherProduct, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, 'ajena.jpg');

        // Una muestra del otro negocio apuntando al producto de este negocio: no
        // debe sumar en el `samples` del listado propio.
        SmartPosProductSample::create([
            'subscription_id' => $otherBranch->subscription_id,
            'branch_id' => $otherBranch->id,
            'product_id' => $ownProduct->id,
            'model_version' => 'mock',
            'embedding' => $this->unitEmbedding(),
            'dim' => 384,
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
        ]);

        $foreign = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk();

        $this->assertSame([$otherProduct->id], $this->productIds($foreign->json('items')));
        $this->assertSame(1, $foreign->json('total_pending'));

        $this->actingAs($this->user);

        $own = $this->getJson(route('pos.smart-checkout.gallery.candidates', [
            'filter' => 'all',
        ]))->assertOk();

        $this->assertSame([$ownProduct->id], $this->productIds($own->json('items')));
        $this->assertSame(0, $own->json('items.0.samples'));
        $this->assertSame(1, $own->json('items.0.pending'));
        $this->assertSame(1, $own->json('total_pending'));
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

    /**
     * Producto con una imagen general real.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeProductWithImage(string $fileName, array $overrides = [], ?Branch $branch = null): Product
    {
        $product = $this->makeProduct($overrides, $branch);

        $this->addImage($product, SmartCheckoutCatalogGalleryService::GENERAL_COLLECTION, $fileName);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $customProperties
     */
    private function addImage(Product $product, string $collection, string $fileName, array $customProperties = []): void
    {
        $adder = $product->addMediaFromString($this->jpegBytes())
            ->usingFileName($fileName);

        if ($customProperties !== []) {
            $adder->withCustomProperties($customProperties);
        }

        $adder->toMediaCollection($collection);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function makeVariant(Product $product, array $attributes): ProductAttribute
    {
        return $product->productAttributes()->create([
            'attributes' => $attributes,
            'sku_suffix' => '-'.strtoupper(implode('-', array_values($attributes))),
            'selling_price_modifier' => 0,
        ]);
    }

    private function addSample(Product $product, string $modelVersion = 'mock'): SmartPosProductSample
    {
        return SmartPosProductSample::create([
            'subscription_id' => $this->branch->subscription_id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'model_version' => $modelVersion,
            'embedding' => $this->unitEmbedding(),
            'dim' => 384,
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
            'quality' => 0.9,
        ]);
    }

    /**
     * Vector unitario empaquetado como float32 little-endian (el formato de la galería).
     *
     * El `...` no es decorativo: sin él `pack()` recibe **un solo argumento** (el
     * array) y empaqueta 4 bytes en vez de 1536, de modo que la galería descarta la
     * fila al servirla (`total` cuenta 1 pero `count` sale 0) y la prueba pasaría
     * por la razón equivocada.
     */
    private function unitEmbedding(): string
    {
        return pack('g*', ...array_fill(0, 384, 1 / sqrt(384)));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, int>
     */
    private function productIds(?array $items): array
    {
        return array_map(static fn (array $item): int => (int) $item['product_id'], $items ?? []);
    }

    /**
     * Un JPEG real y pequeño (GD forma parte del PHP del proyecto).
     */
    private function jpegBytes(int $width = 40, int $height = 40): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('La extensión GD es necesaria para generar las fotos de prueba.');
        }

        $image = imagecreatetruecolor($width, $height);

        for ($index = 0; $index < 12; $index++) {
            $color = imagecolorallocate(
                $image,
                ($index * 31) % 256,
                ($index * 67) % 256,
                ($index * 103) % 256,
            );

            imagefilledrectangle(
                $image,
                ($index * 9) % max(1, $width),
                ($index * 5) % max(1, $height),
                min($width - 1, ($index * 23) % max(1, $width)),
                min($height - 1, ($index * 11) % max(1, $height)),
                $color,
            );
        }

        ob_start();
        imagejpeg($image, null, 70);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
