<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vector gallery of the "Modo Caja Inteligente" (Phase 3).
 *
 * One row per product sample: the embedding (384 float32 = 1536 bytes by
 * default) of a crop of the product, plus the metadata the browser needs to
 * rebuild its `Float32Array` and to decide whether the sample is trustworthy.
 *
 * Additive on purpose: **no existing table is modified**. The gallery is derived
 * from this table on every read (see SmartCheckoutGalleryService): there is no
 * `gallery_version` column anywhere, it is `COUNT(*)` + `MAX(updated_at)` +
 * `MAX(id)` of the tenant. Storing it would need a write on every sample change,
 * would duplicate state that can go stale, and would modify `smartpos_settings`.
 *
 * `embedding` is declared with `binary()`: MySQL maps it to `BLOB` (65 535 bytes)
 * and SQLite to `BLOB`. That is 16× the largest vector this phase accepts
 * (1024 dims × 4 bytes = 4 096 bytes), so the 64 KB ceiling of `BLOB` is not a
 * limit here and the MySQL-only `LONGBLOB` type the specification mentions is
 * unnecessary (Laravel's schema builder has no portable `longBlob()` anyway).
 * All floats are **little-endian float32** (`pack('g*')`), which is exactly what
 * `new Float32Array(arrayBuffer)` expects on every platform the POS runs on.
 *
 * `crop_path` is **relative** to the private disk root (`storage/app/private`),
 * never an absolute path: the file lives at
 * `storage/app/private/smartpos/crops/{subscription_id}/{product_id}/{uuid}.jpg`
 * and is only written when the business has `save_crops` enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smartpos_product_samples', function (Blueprint $table) {
            $table->id();

            // Tenant (gallery scope) and the branch of the product (traceability
            // and the ownership check of a sample). Both are always resolved from
            // the authenticated user, never from the payload.
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Which model produced the vector. A model has one output width, so
            // every sample of the same (tenant, model_version) has the same `dim`
            // (enforced at write time by the service).
            $table->string('model_version', 64);

            // Raw float32 little-endian buffer (see the class docblock).
            $table->binary('embedding');

            $table->unsignedSmallInteger('dim')->default(384);

            // How the sample was captured. Kept as a short string instead of a
            // DB enum so adding a source is a code change, not a migration.
            $table->string('source', 24);

            // Cropped JPEG on the private disk (relative path) or null when the
            // business has the crops disabled.
            $table->string('crop_path')->nullable();

            // Optional confidence/quality of the sample (0.000 - 1.000). When the
            // gallery of a product is full, the lowest quality is the one rotated
            // out first.
            $table->decimal('quality', 4, 3)->nullable();

            $table->timestamps();

            // Direct lookups and the two reads of the gallery: by tenant + model
            // (the batch the browser downloads) and by branch + product (the
            // per-product rotation of the ten newest samples).
            $table->index('subscription_id');
            $table->index('branch_id');
            $table->index('product_id');
            $table->index(['subscription_id', 'model_version']);
            $table->index(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smartpos_product_samples');
    }
};
