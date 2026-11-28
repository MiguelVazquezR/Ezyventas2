<?php

namespace Tests\Feature\SmartCheckout;

use App\Enums\PlanItemType;
use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use App\Services\SmartCheckout\SmartPosSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Degradation of the smart POS when `smartpos_settings` is not migrated yet.
 *
 * The table is created by a migration that may still be pending on an already
 * deployed installation: the POS must keep rendering with the scale off instead
 * of throwing "table not found".
 */
class SmartPosSettingsDegradationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    #[Test]
    public function it_returns_disabled_defaults_and_keeps_the_pos_working_when_the_table_is_missing(): void
    {
        $this->withoutMiddleware([
            CheckOnboardingStatus::class,
            CheckSubscriptionStatus::class,
            EnsureSubscriptionScope::class,
        ]);

        // Platform support user (id 1 passes every gate): create it first so the
        // cashier of this test is a regular one.
        User::factory()->create();

        $this->branch = $this->makeTenant();
        $this->createPosAccessPermission();

        $user = User::factory()->create(['branch_id' => $this->branch->id]);

        // Simulates the deployment where the new migration has not been run.
        Schema::dropIfExists('smartpos_settings');

        $settings = $this->app->make(SmartPosSettingsService::class)
            ->forSubscription((int) $this->branch->subscription_id);

        // Defaults, everything off, and nothing was silently persisted.
        $this->assertFalse($settings->exists);
        $this->assertFalse($settings->scale_enabled);
        $this->assertFalse($settings->smart_checkout_enabled);
        $this->assertSame(5, $settings->scale_settings['stability']['readings']);
        $this->assertSame(3, $settings->scale_settings['stability']['tolerance_g']);
        $this->assertSame('kg', $settings->scale_settings['unit']);

        $this->actingAs($user);

        $this->assertFalse(
            $this->app->make(SmartPosSettingsService::class)->scaleEnabledFor($user),
        );
        $this->assertFalse(
            $this->app->make(SmartPosSettingsService::class)->smartCheckoutEnabledFor($user),
        );

        // The Point of Sale still responds, with the scale prop off. The
        // permissions exist (they are created by a separate data migration), so
        // `can_use` is true: what degrades is the business preference, not the
        // permission.
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scale.enabled', false)
                ->where('scale.can_use', true)
                ->where('scale.can_configure', true)
                ->where('scale.settings', null)
            );
    }

    /**
     * Branch of a fresh tenant with the Point of Sale module contracted.
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

    private function createPosAccessPermission(): void
    {
        Permission::create([
            'name' => 'pos.access',
            'description' => 'Acceder al Punto de Venta',
            'module' => 'Punto de Venta',
            'guard_name' => 'web',
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
