<?php

namespace Tests\Feature\SmartCheckout;

use App\Enums\PlanItemType;
use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Product;
use App\Models\SmartPosSetting;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Catalog of products that can be weighed on the POS scale.
 *
 * A product is weighable when `is_bulk = true` and its `measure_unit` is a weight
 * unit (decision D3). Only the branch of the cashier is listed.
 */
class ScaleProductsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckOnboardingStatus::class,
            CheckSubscriptionStatus::class,
            EnsureSubscriptionScope::class,
        ]);

        // Platform support user (id 1 passes every gate): create it first so the
        // tested user is a regular one.
        User::factory()->create();

        $this->branch = $this->makeTenant();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        // The only permission involved is the one that protects the POS module;
        // the scale has no permission of its own.
        Permission::create([
            'name' => 'pos.access',
            'description' => 'Acceder al Punto de Venta',
            'module' => 'Punto de Venta',
            'guard_name' => 'web',
        ]);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->enableScale();

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_lists_only_bulk_products_sold_by_weight_in_the_cashier_branch(): void
    {
        $harina = Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Harina de trigo',
            'sku' => 'HAR-001',
            'is_bulk' => true,
            'measure_unit' => 'Kg',
            'selling_price' => 32.50,
        ]);

        $azucar = Product::factory()->withStock($this->branch->id, 20)->create([
            'name' => 'Azúcar estándar',
            'sku' => 'AZU-002',
            'is_bulk' => true,
            'measure_unit' => 'grs ',
            'selling_price' => 28.00,
        ]);

        // Bulk but not sold by weight: pieces cannot be weighed.
        Product::factory()->withStock($this->branch->id, 10)->create([
            'name' => 'Cinta adhesiva',
            'sku' => 'CIN-003',
            'is_bulk' => true,
            'measure_unit' => 'Pza',
        ]);

        // Weight unit but not bulk.
        Product::factory()->withStock($this->branch->id, 10)->create([
            'name' => 'Refresco de lata',
            'sku' => 'REF-004',
            'is_bulk' => false,
            'measure_unit' => 'Kg',
        ]);

        // Another branch, same subscription.
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->branch->subscription_id]);
        Product::factory()->withStock($otherBranch->id, 10)->create([
            'name' => 'Harina ajena',
            'sku' => 'HAR-999',
            'is_bulk' => true,
            'measure_unit' => 'kg',
        ]);

        $response = $this->getJson(route('pos.smart-checkout.scale.products'))->assertOk();

        $data = $response->json('data');

        $this->assertEqualsCanonicalizing(
            [$harina->id, $azucar->id],
            array_column($data, 'id'),
        );

        // The payload is the POS catalog payload, so the panel can reuse the card.
        $this->assertArrayHasKey('is_bulk', $data[0]);
        $this->assertArrayHasKey('measure_unit', $data[0]);
        $this->assertArrayHasKey('selling_price', $data[0]);
        $this->assertArrayHasKey('stock', $data[0]);
    }

    #[Test]
    public function it_searches_weighable_products_by_name_and_sku(): void
    {
        Product::factory()->withStock($this->branch->id, 50)->create([
            'name' => 'Harina de trigo',
            'sku' => 'HAR-001',
            'is_bulk' => true,
            'measure_unit' => 'Kg',
        ]);

        Product::factory()->withStock($this->branch->id, 20)->create([
            'name' => 'Azúcar estándar',
            'sku' => 'AZU-002',
            'is_bulk' => true,
            'measure_unit' => 'Grs',
        ]);

        // Another branch with a matching name: the search must not leak it.
        $otherBranch = Branch::factory()->create(['subscription_id' => $this->branch->subscription_id]);
        Product::factory()->withStock($otherBranch->id, 10)->create([
            'name' => 'Harina ajena',
            'sku' => 'HAR-999',
            'is_bulk' => true,
            'measure_unit' => 'kg',
        ]);

        $this->getJson(route('pos.smart-checkout.scale.products', ['search' => 'Harina']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Harina de trigo');

        $this->getJson(route('pos.smart-checkout.scale.products', ['search' => 'AZU-002']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', 'AZU-002');
    }

    #[Test]
    public function it_returns_403_when_the_scale_is_disabled_for_the_business(): void
    {
        SmartPosSetting::where('subscription_id', $this->branch->subscription_id)
            ->update(['scale_enabled' => false]);

        $this->getJson(route('pos.smart-checkout.scale.products'))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Branch of a fresh tenant with the Point of Sale module contracted, so the
     * POS routes (`can:pos.access`) are reachable by the owner of the tenant.
     */
    private function makeTenant(): Branch
    {
        $subscription = Subscription::factory()->create();

        SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
        ]);

        PlanItem::firstOrCreate(
            ['key' => 'module_pos'],
            [
                'type' => PlanItemType::MODULE,
                'name' => 'Punto de Venta',
                'monthly_price' => 0,
                'is_active' => true,
            ],
        );

        SubscriptionItem::create([
            'subscription_version_id' => $subscription->currentVersion()->id,
            'item_key' => 'module_pos',
            'item_type' => 'module',
            'name' => 'Punto de Venta',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        return Branch::factory()->create(['subscription_id' => $subscription->id]);
    }

    private function enableScale(): SmartPosSetting
    {
        return SmartPosSetting::create([
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
        ]);
    }
}
