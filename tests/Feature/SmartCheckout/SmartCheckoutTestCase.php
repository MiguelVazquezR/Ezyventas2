<?php

namespace Tests\Feature\SmartCheckout;

use App\Enums\PlanItemType;
use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\SmartPosSetting;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Shared tenant fixture of the "Modo Caja Inteligente" Phase 2 tests.
 *
 * Every class gets a subscription with the Point of Sale module contracted, its
 * branch and a regular cashier with the two existing POS permissions. The module
 * has **no permissions of its own**: `pos.access` is the only gate.
 */
abstract class SmartCheckoutTestCase extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckOnboardingStatus::class,
            CheckSubscriptionStatus::class,
            EnsureSubscriptionScope::class,
        ]);

        // Platform support user (id 1 passes every gate): created first so the
        // cashier of these tests is a regular one.
        User::factory()->create();

        $this->branch = $this->makeTenant();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->createPosPermissions();

        $this->actingAs($this->user);
    }

    /**
     * Branch of a fresh tenant with the Point of Sale module contracted.
     */
    protected function makeTenant(): Branch
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

    /**
     * The only permissions involved are the existing POS ones: the smart checkout
     * deliberately carries none.
     */
    protected function createPosPermissions(): void
    {
        foreach ([
            'pos.access' => 'Acceder al Punto de Venta',
            'pos.create_sale' => 'Crear nuevas ventas',
        ] as $name => $description) {
            Permission::create([
                'name' => $name,
                'description' => $description,
                'module' => 'Punto de Venta',
                'guard_name' => 'web',
            ]);
        }

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Enables the mode for a subscription (the cashier's one by default).
     */
    protected function enableSmartCheckout(?int $subscriptionId = null): SmartPosSetting
    {
        return SmartPosSetting::query()->updateOrCreate(
            ['subscription_id' => $subscriptionId ?? $this->branch->subscription_id],
            ['smart_checkout_enabled' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function validSmartCheckoutPayload(): array
    {
        return [
            'thresholds' => [
                'green_min' => 0.9,
                'green_margin' => 0.05,
                'yellow_min' => 0.7,
            ],
            'auto_accept' => false,
            'cameras' => [
                [
                    'id' => 'cam-1',
                    'label' => 'Cámara mostrador',
                    'mode' => 'zone',
                    'resolution' => '1280x720',
                ],
            ],
            'max_cameras_per_cycle' => 1,
            'save_crops' => false,
            'model_version' => 'mock-v2',
        ];
    }
}
