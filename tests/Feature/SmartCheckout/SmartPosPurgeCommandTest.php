<?php

namespace Tests\Feature\SmartCheckout;

use App\Models\Branch;
use App\Models\Product;
use App\Models\SmartPosDetectionEvent;
use App\Models\SmartPosProductSample;
use App\Models\SmartPosSetting;
use App\Services\SmartCheckout\SmartCheckoutGalleryService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;

/**
 * Retención de datos del "Modo Caja Inteligente" (Fase 5):
 * `php artisan smartpos:purge`.
 *
 * The command is the scheduled counterpart of
 * SmartCheckoutGalleryService::purgeExpiredCrops(): it deletes the detection
 * events of each business older than its `retention_days` and reuses the gallery
 * service for the crops. These tests pin down the rules that matter:
 *
 *  - only rows older than the retention go away; recent ones stay,
 *  - each business uses its own `retention_days` (30 by default, 0 falls back to
 *    the default),
 *  - `--days` forces the deadline for every business,
 *  - `--subscription` limits the purge and never leaks into another tenant,
 *  - `--dry-run` counts without deleting and says so,
 *  - a purged crop loses its file and its `crop_path` but **keeps the row and the
 *    vector** (deleting vectors by age is forbidden),
 *  - a deployment without `smartpos_settings` is warned about and exits 0.
 *
 * Crops live on a faked private disk, so nothing is written under storage/app.
 */
class SmartPosPurgeCommandTest extends SmartCheckoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic crops: nothing is written to storage/app/private during tests.
        Storage::fake(SmartCheckoutGalleryService::CROP_DISK);
    }

    /*
    |--------------------------------------------------------------------------
    | EVENTOS
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_deletes_events_older_than_the_retention_and_keeps_recent_ones(): void
    {
        // Business without a retention_days of its own: the default (30) applies.
        $this->enableSmartCheckout();

        $expired = $this->makeEvent($this->branch, now()->subDays(31));
        $recent = $this->makeEvent($this->branch, now()->subDay());

        $this->artisan('smartpos:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $expired->id]);
        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $recent->id]);
    }

    #[Test]
    public function it_respects_the_retention_days_of_each_business(): void
    {
        // A (the cashier's tenant) keeps the 30-day default; B only keeps 5 days.
        $this->enableSmartCheckout();

        $otherBranch = $this->makeTenant();

        SmartPosSetting::query()->updateOrCreate(
            ['subscription_id' => $otherBranch->subscription_id],
            ['smart_checkout_enabled' => true, 'retention_days' => 5],
        );

        $tenDaysOldA = $this->makeEvent($this->branch, now()->subDays(10));
        $tenDaysOldB = $this->makeEvent($otherBranch, now()->subDays(10));

        $this->artisan('smartpos:purge')->assertExitCode(0);

        // Ten days is inside A's window and outside B's.
        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $tenDaysOldA->id]);
        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $tenDaysOldB->id]);
    }

    #[Test]
    public function it_falls_back_to_the_default_retention_when_the_business_value_is_not_positive(): void
    {
        // A stored 0 means "not configured": the service falls back to 30.
        SmartPosSetting::query()->create([
            'subscription_id' => $this->branch->subscription_id,
            'smart_checkout_enabled' => true,
            'retention_days' => 0,
        ]);

        $expired = $this->makeEvent($this->branch, now()->subDays(31));
        $recent = $this->makeEvent($this->branch, now()->subDays(29));

        $this->artisan('smartpos:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $expired->id]);
        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $recent->id]);
    }

    #[Test]
    public function it_forces_the_deadline_of_every_business_with_the_days_option(): void
    {
        $this->enableSmartCheckout();

        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);

        // Five days would survive both default windows (30 and 30)…
        $eventA = $this->makeEvent($this->branch, now()->subDays(5));
        $eventB = $this->makeEvent($otherBranch, now()->subDays(5));

        // …and so would a five-day-old crop, which is what makes this assertion
        // worth having: `--days` must move *both* deadlines, not only the events.
        $sample = $this->makeSample($this->branch, now()->subDays(5));
        $cropPath = $sample->crop_path;

        $this->artisan('smartpos:purge', ['--days' => 1])->assertExitCode(0);

        // …but not the forced one-day window.
        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $eventA->id]);
        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $eventB->id]);

        // The crop file goes, the row and the vector stay.
        $this->assertFalse(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($cropPath));
        $this->assertNull($sample->fresh()->crop_path);
        $this->assertDatabaseHas('smartpos_product_samples', ['id' => $sample->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | ALCANCE Y SIMULACIÓN
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_only_purges_the_subscription_given_to_the_subscription_option(): void
    {
        $this->enableSmartCheckout();

        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);

        $mine = $this->makeEvent($this->branch, now()->subDays(31));
        $theirs = $this->makeEvent($otherBranch, now()->subDays(31));

        $this->artisan('smartpos:purge', ['--subscription' => $otherBranch->subscription_id])
            ->doesntExpectOutputToContain('Suscripción '.$this->branch->subscription_id.' ·')
            ->assertExitCode(0);

        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $mine->id]);
        $this->assertDatabaseMissing('smartpos_detection_events', ['id' => $theirs->id]);
    }

    #[Test]
    public function it_does_not_delete_anything_in_dry_run_and_says_so(): void
    {
        $this->enableSmartCheckout();

        $event = $this->makeEvent($this->branch, now()->subDays(31));
        $sample = $this->makeSample($this->branch, now()->subDays(31));
        $cropPath = $sample->crop_path;

        // The output is captured so the test also pins down what the operator
        // reads in a simulation.
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->call('smartpos:purge', ['--dry-run' => true]);
        $output = $kernel->output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('eventos a borrar: 1', $output);
        $this->assertStringContainsString('recortes a purgar: 1', $output);
        $this->assertStringContainsString('no se borró nada', $output);

        // Nothing was deleted: neither the row, nor the crop reference, nor the file.
        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $event->id]);
        $this->assertNotNull(SmartPosProductSample::query()->findOrFail($sample->id)->crop_path);
        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($cropPath));
    }

    #[Test]
    public function it_never_touches_rows_of_another_subscription(): void
    {
        $this->enableSmartCheckout();

        $otherBranch = $this->makeTenant();
        $this->enableSmartCheckout($otherBranch->subscription_id);

        // Old enough for any retention: only the scope keeps them alive.
        $foreignEvent = $this->makeEvent($otherBranch, now()->subDays(90));
        $foreignSample = $this->makeSample($otherBranch, now()->subDays(90));
        $foreignCrop = $foreignSample->crop_path;

        $this->artisan('smartpos:purge', ['--subscription' => $this->branch->subscription_id])
            ->assertExitCode(0);

        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $foreignEvent->id]);
        $this->assertNotNull(SmartPosProductSample::query()->findOrFail($foreignSample->id)->crop_path);
        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($foreignCrop));
    }

    /*
    |--------------------------------------------------------------------------
    | RECORTES
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_purges_expired_crops_but_keeps_the_row_and_the_vector(): void
    {
        $this->enableSmartCheckout();

        $embedding = $this->binaryVector();

        $expired = $this->makeSample($this->branch, now()->subDays(31), $embedding);
        $expiredCrop = $expired->crop_path;
        $expiredUpdatedAt = $expired->updated_at->toDateTimeString();

        $recent = $this->makeSample($this->branch, now()->subDay(), $embedding);
        $recentCrop = $recent->crop_path;

        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($expiredCrop));
        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($recentCrop));

        $this->artisan('smartpos:purge')
            ->expectsOutputToContain('recortes purgados: 1')
            ->assertExitCode(0);

        // The expired crop file is gone and its reference is cleared…
        $this->assertFalse(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($expiredCrop));

        $refreshed = SmartPosProductSample::query()->findOrFail($expired->id);

        $this->assertNull($refreshed->crop_path);

        // …but the row, the vector and `updated_at` (the derived gallery version)
        // are untouched: this phase never deletes vectors by age.
        $this->assertSame($embedding, $refreshed->getAttributes()['embedding']);
        $this->assertSame($expiredUpdatedAt, $refreshed->updated_at->toDateTimeString());

        // The recent crop is not part of the retention window.
        $this->assertTrue(Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->exists($recentCrop));
        $this->assertSame($recentCrop, SmartPosProductSample::query()->findOrFail($recent->id)->crop_path);
    }

    /*
    |--------------------------------------------------------------------------
    | DESPLIEGUE SIN EL MÓDULO
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_warns_and_exits_successfully_when_the_settings_table_is_missing(): void
    {
        $this->enableSmartCheckout();

        $event = $this->makeEvent($this->branch, now()->subDays(60));

        // Simulates the deployment where the new migration has not been run.
        Schema::dropIfExists('smartpos_settings');

        $this->artisan('smartpos:purge')
            ->expectsOutputToContain('no está instalado')
            ->assertExitCode(0);

        // A broken deployment never loses data because of the scheduled task.
        $this->assertDatabaseHas('smartpos_detection_events', ['id' => $event->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * One detection event of the branch, with an explicit `occurred_at` (the
     * column the retention is based on).
     */
    private function makeEvent(Branch $branch, CarbonInterface $occurredAt): SmartPosDetectionEvent
    {
        return SmartPosDetectionEvent::create([
            'subscription_id' => $branch->subscription_id,
            'branch_id' => $branch->id,
            'session_id' => (string) Str::uuid(),
            'event_type' => SmartPosDetectionEvent::TYPE_ANALYSIS,
            'occurred_at' => $occurredAt,
        ]);
    }

    /**
     * One sample with a crop file on the fake disk and an `updated_at` old enough
     * to be outside (or inside) the retention window.
     *
     * `forceFill` is needed because `SmartPosProductSample::create()` would stamp
     * the row with "now": the retention of the crops is measured on `updated_at`.
     */
    private function makeSample(Branch $branch, CarbonInterface $updatedAt, ?string $embedding = null): SmartPosProductSample
    {
        $product = Product::factory()
            ->withStock($branch->id)
            ->create(['branch_id' => $branch->id]);

        $cropPath = SmartCheckoutGalleryService::CROP_DIRECTORY.'/'.$branch->subscription_id.'/'.$product->id.'/'.Str::uuid()->toString().'.jpg';

        Storage::disk(SmartCheckoutGalleryService::CROP_DISK)->put($cropPath, 'recorte-de-prueba');

        $sample = SmartPosProductSample::create([
            'subscription_id' => $branch->subscription_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'model_version' => 'mock',
            'embedding' => $embedding ?? $this->binaryVector(),
            'dim' => 384,
            'source' => SmartPosProductSample::SOURCE_CATALOG_IMAGE,
            'crop_path' => $cropPath,
        ]);

        $sample->forceFill(['updated_at' => $updatedAt])->save();

        return $sample->fresh();
    }

    /**
     * A realistic 1 536-byte embedding (384 float32), the default dimension.
     */
    private function binaryVector(): string
    {
        return pack('g*', ...array_fill(0, 384, 0.5));
    }
}
