<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\Product;
use App\Models\SmartPosProductSample;
use App\Models\User;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Vector gallery of the "Modo Caja Inteligente" (Phase 3).
 *
 * The browser downloads the embeddings of its tenant as one base64 batch of
 * little-endian float32, keeps them in a `Float32Array` and searches by cosine
 * similarity in memory. These tests cover the contract of the three endpoints:
 *
 *  - the business preference gates every call (403 while the mode is off),
 *  - the vector survives the JSON → float32 → JSON round trip **bit for bit**,
 *  - dimension, length, finite range and source are validated with a 422 JSON,
 *  - a product of another branch is rejected and nothing is written,
 *  - the gallery never leaks samples of another subscription,
 *  - at most ten samples per product and model version, rotating the weakest one,
 *  - `ETag` / `If-None-Match` answer 304 without a body,
 *  - the crop is written only when `save_crops` is on, always as a relative path,
 *  - a sample of another tenant cannot be deleted.
 *
 * The throttle of the POST route is disabled here: the limiter keys on the
 * authenticated user and every test reuses (and recreates) the same user ids in a
 * single PHP process, so the 60-per-minute budget would leak from test to test.
 * `the_gallery_routes_are_public_to_pos_users_and_the_samples_route_is_throttled`
 * asserts the middleware is attached instead.
 */
class SmartCheckoutGalleryTest extends SmartCheckoutTestCase
{
    /** Width used by the default model. */
    private const DIM = 384;

    /** Bytes of one vector (dim × 4). */
    private const BYTES = 1536;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        // Hermetic crops: nothing is written to storage/app/private during tests.
        Storage::fake(SmartCheckoutGalleryService::CROP_DISK);
    }

    /*
    |--------------------------------------------------------------------------
    | PUERTA DEL MÓDULO
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_403_while_the_mode_is_disabled(): void
    {
        $product = $this->makeProduct();

        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))
            ->assertForbidden()
            ->assertJsonPath('message', 'El Modo Caja Inteligente no está habilitado para este negocio.');

        $this->assertDatabaseCount('smartpos_product_samples', 0);
    }

    #[Test]
    public function it_returns_an_empty_gallery_when_the_mode_is_enabled(): void
    {
        $this->enableSmartCheckout();

        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('vectors', '')
            ->assertJsonPath('items', [])
            ->assertJsonPath('dim', self::DIM)
            ->assertJsonPath('model_version', 'mock')
            // The derived version of an empty tenant.
            ->assertJsonPath('gallery_version', '0-0-0')
            ->assertJsonPath('next_cursor', null)
            ->assertHeader('ETag');
    }

    /*
    |--------------------------------------------------------------------------
    | IDA Y VUELTA BINARIA
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_stores_a_sample_and_serves_it_back_bit_for_bit(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $vector = $this->unitVector();

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'vector' => $vector,
            'source' => 'cashier_correction',
            'quality' => 0.91,
        ]))
            ->assertCreated()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('source', 'cashier_correction')
            ->assertJsonPath('samples_for_product', 1)
            ->assertJsonStructure(['id', 'product_id', 'source', 'samples_for_product', 'gallery_version']);

        $this->assertMatchesRegularExpression('/^\d+-\d+-\d+$/', (string) $created->json('gallery_version'));

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));

        // Tenant, branch and dimension always come from the user and the payload.
        $this->assertSame((int) $this->branch->subscription_id, $sample->subscription_id);
        $this->assertSame((int) $this->branch->id, $sample->branch_id);
        $this->assertSame(self::DIM, $sample->dim);
        $this->assertSame(self::BYTES, $sample->embeddingBytes());
        $this->assertSame(1536, strlen($sample->getAttributes()['embedding']));
        $this->assertNull($sample->crop_path);

        // The binary stored is exactly the little-endian float32 of the vector.
        $this->assertSame(pack('g*', ...$vector), $sample->getAttributes()['embedding']);

        $gallery = $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('dim', self::DIM)
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.offset', 0)
            ->assertJsonPath('items.0.length', self::BYTES)
            ->assertJsonPath('items.0.source', 'cashier_correction')
            ->assertJsonPath('items.0.model_version', 'mock')
            ->assertJsonPath('items.0.quality', 0.91);

        $binary = base64_decode((string) $gallery->json('vectors'), true);

        $this->assertIsString($binary);
        $this->assertSame(self::BYTES, strlen($binary));

        // 1) Bit for bit: the buffer the browser receives is byte-identical to
        //    the one produced by packing the vector that was sent.
        $this->assertSame(pack('g*', ...$vector), $binary);

        // 2) The browser rebuilds the Float32Array from those bytes and gets the
        //    very same numbers back (no precision drift in the round trip).
        $rebuilt = array_values(unpack('g*', $binary));

        $this->assertCount(self::DIM, $rebuilt);
        $this->assertSame($vector, $rebuilt);

        // 3) A second upload of the same vector is idempotent (the stored buffer
        //    does not depend on the normalisation path).
        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'vector' => $vector,
        ]))->assertCreated();

        $this->assertSame(
            2,
            SmartPosProductSample::query()
                ->where('product_id', $product->id)
                ->where('embedding', pack('g*', ...$vector))
                ->count(),
        );
    }

    #[Test]
    public function it_accepts_the_base64_form_of_the_vector(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $vector = $this->unitVector();
        $encoded = base64_encode(pack('g*', ...$vector));

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'vector' => $encoded,
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));

        $this->assertSame(pack('g*', ...$vector), $sample->getAttributes()['embedding']);
        $this->assertSame(self::BYTES, $sample->embeddingBytes());
    }

    #[Test]
    public function it_normalises_a_vector_that_is_not_unit_length(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        // A constant vector of 2.0 has an L2 norm of 2 √384 ≈ 39.19.
        $vector = array_fill(0, self::DIM, 2.0);

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'vector' => $vector,
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));
        $stored = array_values(unpack('g*', $sample->getAttributes()['embedding']));

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $stored)));

        $this->assertEqualsWithDelta(1.0, $norm, 1.0e-6);
        $this->assertEqualsWithDelta(1.0 / sqrt(self::DIM), $stored[0], 1.0e-6);
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDACIÓN
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_invalid_dimensions_lengths_ranges_and_sources(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $vector = $this->unitVector();

        $notANumber = $vector;
        $notANumber[17] = 'mucho';

        $invalidPayloads = [
            'dimension below the minimum' => [['dim' => 64, 'vector' => array_fill(0, 64, 0.1)], 'dim'],
            'dimension above the maximum' => [['dim' => 2048, 'vector' => array_fill(0, 2048, 0.1)], 'dim'],
            'dimension not an integer' => [['dim' => '384.5'], 'dim'],
            'vector shorter than the dimension' => [['vector' => array_slice($vector, 0, self::DIM - 1)], 'vector'],
            'vector longer than the dimension' => [['vector' => array_merge($vector, [0.5])], 'vector'],
            'component that is not a number' => [['vector' => $notANumber], 'vector'],
            'vector that is the zero vector' => [['vector' => array_fill(0, self::DIM, 0.0)], 'vector'],
            'base64 that is not base64' => [['vector' => 'esto-no-es-base64'], 'vector'],
            'base64 of the wrong size' => [['vector' => base64_encode(str_repeat("\x00", 100))], 'vector'],
            'unknown source' => [['source' => 'wifi'], 'source'],
            'quality above one' => [['quality' => 1.5], 'quality'],
            'crop that is not a string' => [['crop' => ['a' => 1]], 'crop'],
            'missing model version' => [['model_version' => ''], 'model_version'],
            'missing product' => [['product_id' => null], 'product_id'],
        ];

        foreach ($invalidPayloads as $case => [$overrides, $errorKey]) {
            $response = $this->postJson(
                route('pos.smart-checkout.samples.store'),
                $this->samplePayload($product, $overrides),
            )->assertStatus(422);

            $response->assertJsonValidationErrors($errorKey);

            // The message that surfaces is a Spanish one, not the framework's.
            $this->assertNotSame('', (string) $response->json('message'));
        }

        $this->assertDatabaseCount('smartpos_product_samples', 0);
    }

    #[Test]
    public function it_rejects_a_product_of_another_branch_and_writes_nothing(): void
    {
        $this->enableSmartCheckout();

        // Same subscription, different branch: the product is not in the cashier's
        // catalog, so its vector would never be usable at that terminal.
        $otherBranch = Branch::factory()->create([
            'subscription_id' => $this->branch->subscription_id,
        ]);

        $foreignProduct = Product::factory()
            ->withStock($otherBranch->id)
            ->create(['branch_id' => $otherBranch->id]);

        $this->postJson(
            route('pos.smart-checkout.samples.store'),
            $this->samplePayload($foreignProduct),
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_id')
            ->assertJsonPath('message', 'El producto indicado no pertenece a la sucursal del cajero.');

        $this->assertDatabaseCount('smartpos_product_samples', 0);
    }

    #[Test]
    public function it_rejects_a_dimension_that_does_not_match_the_gallery_of_the_model(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'dim' => 512,
            'vector' => $this->unitVector(512),
        ]))->assertCreated();

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'dim' => self::DIM,
            'vector' => $this->unitVector(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('dim');

        $this->assertDatabaseCount('smartpos_product_samples', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | AISLAMIENTO ENTRE NEGOCIOS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_never_serves_the_samples_of_another_subscription(): void
    {
        $this->enableSmartCheckout();
        $productA = $this->makeProduct();

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($productA))->assertCreated();

        // A second business, with the mode enabled and one sample of its own.
        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherUser);

        $productB = $this->makeProduct($otherBranch);

        // A different vector, so the two batches cannot match by accident.
        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($productB, [
            'vector' => $this->unitVector(self::DIM, 2),
        ]))->assertCreated();

        $galleryB = $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.product_id', $productB->id);

        $this->actingAs($this->user);

        $galleryA = $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('items.0.product_id', $productA->id);

        // Neither the items nor the buffer nor the ETag travel between tenants.
        $this->assertNotSame($galleryA->json('vectors'), $galleryB->json('vectors'));
        $this->assertNotNull($galleryA->headers->get('ETag'));
        $this->assertNotNull($galleryB->headers->get('ETag'));
        $this->assertNotSame($galleryA->headers->get('ETag'), $galleryB->headers->get('ETag'));

        // Each tenant keeps its own row.
        $this->assertSame(
            1,
            SmartPosProductSample::query()->where('subscription_id', $this->branch->subscription_id)->count(),
        );
        $this->assertSame(
            1,
            SmartPosProductSample::query()->where('subscription_id', $otherBranch->subscription_id)->count(),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LÍMITE Y ROTACIÓN
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_keeps_ten_samples_per_product_and_rotates_the_oldest(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $ids = [];

        for ($index = 1; $index <= SmartCheckoutGalleryService::MAX_SAMPLES_PER_PRODUCT; $index++) {
            $ids[] = $this->postJson(
                route('pos.smart-checkout.samples.store'),
                $this->samplePayload($product),
            )->assertCreated()->json('id');
        }

        $this->assertSame(10, SmartPosProductSample::query()->count());

        // The eleventh sample must survive the rotation.
        $newest = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))
            ->assertCreated()
            ->assertJsonPath('samples_for_product', 10)
            ->json('id');

        $this->assertSame(10, SmartPosProductSample::query()->count());
        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $newest]);

        // The oldest is the one that left; the other nine are still there.
        $this->assertDatabaseMissing('smartpos_product_samples', ['id' => $ids[0]]);

        foreach (array_slice($ids, 1) as $id) {
            $this->assertDatabaseHas('smartpos_product_samples', ['id' => $id]);
        }
    }

    #[Test]
    public function it_rotates_the_sample_with_the_lowest_quality_when_a_quality_is_known(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $ids = [];

        for ($index = 0; $index < SmartCheckoutGalleryService::MAX_SAMPLES_PER_PRODUCT; $index++) {
            $payload = $this->samplePayload($product);

            // Only the fifth sample carries a (bad) quality.
            if ($index === 4) {
                $payload['quality'] = 0.10;
            }

            $ids[] = $this->postJson(route('pos.smart-checkout.samples.store'), $payload)
                ->assertCreated()
                ->json('id');
        }

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'quality' => 0.95,
        ]))->assertCreated()->assertJsonPath('samples_for_product', 10);

        // The weakest sample leaves, even though it is not the oldest one.
        $this->assertDatabaseMissing('smartpos_product_samples', ['id' => $ids[4]]);
        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $ids[0]]);
        $this->assertSame(10, SmartPosProductSample::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | ETAG
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_answers_304_when_the_etag_still_matches(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))->assertCreated();

        $first = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);
        $this->assertStringContainsString('no-cache', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));

        $notModified = $this->withHeaders(['If-None-Match' => $etag])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertStatus(304);

        // No body at all, and the validator travels back so the browser can keep it.
        $this->assertSame('', $notModified->getContent());
        $notModified->assertHeader('ETag', $etag);

        // Weak validators are compared weakly (RFC 7232).
        $this->withHeaders(['If-None-Match' => 'W/'.$etag])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertStatus(304);

        // A comma-separated list is also valid.
        $this->withHeaders(['If-None-Match' => '"otro", '.$etag])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertStatus(304);

        // A new sample changes the gallery version and the ETag.
        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))->assertCreated();

        $after = $this->withHeaders(['If-None-Match' => $etag])
            ->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk();

        $this->assertNotSame($etag, $after->headers->get('ETag'));
        $this->assertNotSame(
            $first->json('gallery_version'),
            $after->json('gallery_version'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECORTES
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_does_not_write_a_crop_when_save_crops_is_disabled(): void
    {
        $this->enableSmartCheckout();

        $this->putJson(route('pos.smart-checkout.settings.update'), ['save_crops' => false])
            ->assertOk()
            ->assertJsonPath('settings.save_crops', false);

        $product = $this->makeProduct();

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => base64_encode($this->jpegBytes(224, 224)),
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));

        $this->assertNull($sample->crop_path);
        $this->assertSame([], Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->allFiles());
    }

    #[Test]
    public function it_writes_the_crop_and_exposes_a_relative_path_when_save_crops_is_enabled(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $jpeg = $this->jpegBytes(224, 224);

        // The contract is relative to the private disk, which is storage/app/private.
        $this->assertSame(
            storage_path('app/private'),
            config('filesystems.disks.'.SmartCheckoutGalleryService::CROP_DISK.'.root'),
        );

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => base64_encode($jpeg),
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));

        $this->assertNotNull($sample->crop_path);
        $this->assertStringStartsWith(
            'smartpos/crops/'.$this->branch->subscription_id.'/'.$product->id.'/',
            $sample->crop_path,
        );
        $this->assertStringEndsWith('.jpg', $sample->crop_path);
        $this->assertStringStartsNotWith('/', $sample->crop_path);
        $this->assertStringNotContainsString(':\\', $sample->crop_path);
        $this->assertStringNotContainsString('/app/', $sample->crop_path);

        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($sample->crop_path));
        $this->assertSame(
            $jpeg,
            Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->get($sample->crop_path),
        );
    }

    #[Test]
    public function it_accepts_a_data_uri_crop(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();
        $jpeg = $this->jpegBytes(96, 96);

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));

        $this->assertNotNull($sample->crop_path);
        $this->assertSame(
            $jpeg,
            Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->get($sample->crop_path),
        );
    }

    #[Test]
    public function it_rejects_a_crop_that_is_not_a_jpeg_of_the_allowed_size(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $png = $this->pngBytes(64, 64);

        $invalidCrops = [
            'not an image' => base64_encode('esto no es una imagen'),
            'png instead of jpeg' => base64_encode($png),
            'larger than 224x224' => base64_encode($this->jpegBytes(300, 300)),
            'larger than 80 KB' => base64_encode($this->paddedJpeg(81930)),
            'base64 too long for the request' => str_repeat('A', SmartCheckoutGalleryService::CROP_MAX_ENCODED_LENGTH + 1),
        ];

        foreach ($invalidCrops as $case => $crop) {
            $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
                'crop' => $crop,
            ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('crop');
        }

        $this->assertDatabaseCount('smartpos_product_samples', 0);
        $this->assertSame([], Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->allFiles());
    }

    #[Test]
    public function it_purges_crops_older_than_the_retention_but_never_the_vector(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $old = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => base64_encode($this->jpegBytes(120, 120)),
        ]))->assertCreated();

        $oldSample = SmartPosProductSample::query()->findOrFail($old->json('id'));
        $oldPath = $oldSample->crop_path;
        $oldUpdatedAt = $oldSample->updated_at->toDateTimeString();

        $this->assertNotNull($oldPath);
        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($oldPath));

        // Past `smartpos_settings.retention_days` (30 by default).
        $this->travel(31)->days();

        $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => base64_encode($this->jpegBytes(120, 120)),
        ]))->assertCreated();

        // The old crop file is gone and its reference is cleared…
        $this->assertFalse(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($oldPath));

        $refreshed = SmartPosProductSample::query()->findOrFail($oldSample->id);

        $this->assertNull($refreshed->crop_path);

        // …but the vector is untouched: this phase never deletes vectors by age.
        $this->assertSame(self::BYTES, $refreshed->embeddingBytes());
        $this->assertSame($oldUpdatedAt, $refreshed->updated_at->toDateTimeString());
    }

    /*
    |--------------------------------------------------------------------------
    | BORRADO
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_deletes_a_sample_of_the_tenant_and_its_crop(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $created = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product, [
            'crop' => base64_encode($this->jpegBytes(224, 224)),
        ]))->assertCreated();

        $sample = SmartPosProductSample::query()->findOrFail($created->json('id'));
        $cropPath = $sample->crop_path;

        $this->assertNotNull($cropPath);

        $this->deleteJson(route('pos.smart-checkout.samples.destroy', $sample->id))
            ->assertNoContent();

        $this->assertDatabaseMissing('smartpos_product_samples', ['id' => $sample->id]);
        $this->assertFalse(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($cropPath));

        // The derived version of the empty gallery is back to zero.
        $this->getJson(route('pos.smart-checkout.gallery'))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('gallery_version', '0-0-0');
    }

    #[Test]
    public function it_does_not_delete_a_sample_of_another_tenant(): void
    {
        $this->enableSmartCheckout();

        $product = $this->makeProduct();

        $sampleId = $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))
            ->assertCreated()
            ->json('id');

        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherUser)
            ->deleteJson(route('pos.smart-checkout.samples.destroy', $sampleId))
            ->assertNotFound()
            ->assertJsonPath('message', 'La muestra indicada no existe en la galería de tu negocio.');

        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $sampleId]);
    }

    /*
    |--------------------------------------------------------------------------
    | PAGINACIÓN Y CONTRATO DE LAS RUTAS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_paginates_the_gallery_when_it_exceeds_the_page_size(): void
    {
        $this->enableSmartCheckout();

        $service = $this->app->make(SmartCheckoutGalleryService::class);

        // Default cap: the page carries 4 MiB of base64, i.e. 2 048 vectors of
        // 384 dimensions (1 536 bytes → 2 048 base64 chars each).
        $this->assertSame(4 * 1024 * 1024, SmartCheckoutGalleryService::MAX_PAGE_BYTES);
        $this->assertSame(2048, $service->samplesPerPage(self::DIM));

        // The cap is a tuning knob for hosts with a small memory_limit; lowering
        // it keeps the batching logic (and this test) cheap: four vectors a page.
        config(['smartpos.gallery.max_page_bytes' => 4 * 2048]);
        $this->assertSame(4, $service->samplesPerPage(self::DIM));

        $product = $this->makeProduct();

        for ($index = 1; $index <= 5; $index++) {
            $this->postJson(route('pos.smart-checkout.samples.store'), $this->samplePayload($product))
                ->assertCreated();
        }

        $first = $this->getJson(route('pos.smart-checkout.gallery'))->assertOk();

        $this->assertSame(4, $first->json('count'));
        $this->assertSame(5, $first->json('total'));
        $this->assertSame(4 * 2048, strlen((string) $first->json('vectors')));
        // Consecutive byte offsets inside the buffer of the page.
        $this->assertSame(0, $first->json('items.0.offset'));
        $this->assertSame(self::BYTES, $first->json('items.1.offset'));
        $this->assertSame(3 * self::BYTES, $first->json('items.3.offset'));
        $this->assertIsInt($first->json('next_cursor'));

        $second = $this->getJson(route('pos.smart-checkout.gallery', [
            'cursor' => $first->json('next_cursor'),
        ]))->assertOk();

        $this->assertSame(1, $second->json('count'));
        $this->assertNull($second->json('next_cursor'));
        // Each page is its own buffer: offsets restart at zero.
        $this->assertSame(0, $second->json('items.0.offset'));
        $this->assertSame(2048, strlen((string) $second->json('vectors')));

        // Two different pages never share an ETag.
        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    #[Test]
    public function the_gallery_routes_are_public_to_pos_users_and_the_samples_route_is_throttled(): void
    {
        $gallery = Route::getRoutes()->getByName('pos.smart-checkout.gallery');
        $store = Route::getRoutes()->getByName('pos.smart-checkout.samples.store');
        $destroy = Route::getRoutes()->getByName('pos.smart-checkout.samples.destroy');

        $this->assertNotNull($gallery);
        $this->assertNotNull($store);
        $this->assertNotNull($destroy);

        $this->assertContains('GET', $gallery->methods());
        $this->assertSame('pos/smart-checkout/gallery', $gallery->uri());

        $this->assertContains('POST', $store->methods());
        $this->assertSame('pos/smart-checkout/samples', $store->uri());
        $this->assertContains('throttle:60,1', $store->gatherMiddleware());
        // No permission of its own: the POS one is the only gate (decisiones.md).
        $this->assertContains('can:pos.access', $store->gatherMiddleware());

        $this->assertContains('DELETE', $destroy->methods());
        $this->assertSame('pos/smart-checkout/samples/{sample}', $destroy->uri());
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * A product of the given branch, attached to its `branch_product` pivot (that
     * is the criterion the POS catalog and the endpoint use).
     */
    private function makeProduct(?Branch $branch = null): Product
    {
        $branch ??= $this->branch;

        return Product::factory()
            ->withStock($branch->id)
            ->create(['branch_id' => $branch->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function samplePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'model_version' => 'mock',
            'dim' => self::DIM,
            'vector' => $this->unitVector(),
            'source' => 'catalog_image',
        ], $overrides);
    }

    /**
     * Deterministic L2-normalised vector, already snapped to float32.
     *
     * Snapping matters: the server packs the floats it receives with `pack('g*')`,
     * so the values this test sends must be exactly representable as float32 for
     * the comparison to be bit for bit (and the norm stays within the tolerance
     * the service uses before normalising).
     *
     * @return array<int, float>
     */
    private function unitVector(int $dim = self::DIM, int $seed = 1): array
    {
        $raw = [];

        for ($index = 1; $index <= $dim; $index++) {
            $raw[] = sin($index * $seed * 0.37);
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $raw)));
        $unit = array_map(static fn (float $value): float => $value / $norm, $raw);

        return array_values(unpack('g*', pack('g*', ...$unit)));
    }

    /**
     * A real JPEG of the given size (GD is part of the project's PHP build).
     */
    private function jpegBytes(int $width, int $height, int $quality = 70): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('La extensión GD es necesaria para generar los recortes de prueba.');
        }

        $image = imagecreatetruecolor($width, $height);

        // A bit of noise so the file has a realistic size.
        for ($index = 0; $index < 24; $index++) {
            $color = imagecolorallocate(
                $image,
                ($index * 37) % 256,
                ($index * 71) % 256,
                ($index * 113) % 256,
            );

            imagefilledrectangle(
                $image,
                ($index * 11) % max(1, $width),
                ($index * 7) % max(1, $height),
                min($width - 1, ($index * 29) % max(1, $width)),
                min($height - 1, ($index * 13) % max(1, $height)),
                $color,
            );
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    /**
     * A valid JPEG padded with trailing bytes, to cross the 80 KB limit while
     * staying inside the length the FormRequest accepts.
     */
    private function paddedJpeg(int $size): string
    {
        $jpeg = $this->jpegBytes(32, 32, 70);
        $this->assertLessThanOrEqual($size, strlen($jpeg));

        return $jpeg.str_repeat("\0", $size - strlen($jpeg));
    }

    /**
     * A real PNG, to prove the MIME type is sniffed from the bytes.
     */
    private function pngBytes(int $width, int $height): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('La extensión GD es necesaria para generar los recortes de prueba.');
        }

        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }
}
