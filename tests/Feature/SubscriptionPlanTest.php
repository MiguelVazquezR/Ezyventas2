<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use App\Models\User;
use App\Services\Subscription\SubscriptionPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The free plan declared in config/subscription.php is open-ended: new accounts
 * get fixed limits and a version that spans the whole plan horizon, so there is
 * nothing to renew until the owner buys a paid plan.
 */
class SubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $subscription;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Storage::fake('public');

        $branch = Branch::factory()->create();
        $this->subscription = $branch->subscription;
        $this->owner = User::factory()->create(['branch_id' => $branch->id]);

        // An account that already finished the onboarding wizard, so the
        // subscription endpoints are reachable in these scenarios.
        $this->subscription->update(['onboarding_completed_at' => now()]);

        $this->createPlanItems();

        app(SubscriptionPlanService::class)->initializeFreePlan($this->subscription);
    }

    #[Test]
    public function the_free_plan_has_no_expiration(): void
    {
        $version = $this->freePlanVersion();
        $planService = app(SubscriptionPlanService::class);

        $this->assertTrue($planService->isOpenEnded($this->subscription));
        $this->assertTrue(
            $version->end_date->greaterThan(now()->addYears($planService->horizonYears() - 1)),
            'The free plan version should cover the whole plan horizon.'
        );
    }

    #[Test]
    public function the_free_plan_ships_the_fixed_limits_from_config(): void
    {
        $planService = app(SubscriptionPlanService::class);
        $quantities = $this->freePlanVersion()->items()
            ->where('item_type', 'limit')
            ->pluck('quantity', 'item_key')
            ->all();

        $this->assertNotEmpty($planService->fixedLimits());

        foreach ($planService->fixedLimits() as $key => $quantity) {
            $this->assertArrayHasKey($key, $quantities, "The free plan is missing the limit {$key}.");
            $this->assertSame($quantity, (int) $quantities[$key]);
        }
    }

    #[Test]
    public function paid_versions_are_not_treated_as_open_ended(): void
    {
        $planService = app(SubscriptionPlanService::class);

        $this->assertTrue($planService->isOpenEndedVersion($this->freePlanVersion()));
        $this->assertFalse($planService->isOpenEndedVersion($this->paidVersion(30)));
        $this->assertFalse($planService->isOpenEndedVersion($this->paidVersion(365)));
        $this->assertFalse($planService->isOpenEndedVersion(null));
        $this->assertFalse($planService->isOpenEndedVersion(new SubscriptionVersion([
            'start_date' => now(),
            'end_date' => null,
        ])));
    }

    #[Test]
    public function upgrading_the_free_plan_starts_today_and_runs_one_billing_period(): void
    {
        $planService = app(SubscriptionPlanService::class);

        $response = $this->actingAs($this->owner)->post(route('subscription.manage.store'), [
            'billing_period' => 'mensual',
            'items' => [['key' => 'module_pos', 'quantity' => 1]],
            'total_amount' => 130,
            'mode' => 'upgrade',
            'payment_method' => 'transferencia',
            'proof_of_payment' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        $response->assertRedirect(route('subscription.show'));

        $newVersion = $this->subscription->versions()->latest('id')->firstOrFail();

        $this->assertTrue($newVersion->start_date->isSameDay(now()));
        $this->assertSame(
            30,
            (int) $newVersion->start_date->diffInDays($newVersion->end_date),
            'An upgrade on the free plan must not inherit the plan horizon as end date.'
        );
        $this->assertFalse($planService->isOpenEndedVersion($newVersion));
        $this->assertFalse($planService->isOpenEnded($this->subscription));
    }

    #[Test]
    public function upgrading_an_active_paid_plan_keeps_its_original_end_date(): void
    {
        $paidVersion = SubscriptionVersion::create([
            'subscription_id' => $this->subscription->id,
            'start_date' => now()->subDays(10),
            'end_date' => now()->addDays(20),
        ]);

        $response = $this->actingAs($this->owner)->post(route('subscription.manage.store'), [
            'billing_period' => 'mensual',
            'items' => [['key' => 'module_pos', 'quantity' => 2]],
            'total_amount' => 260,
            'mode' => 'upgrade',
            'payment_method' => 'transferencia',
            'proof_of_payment' => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        $response->assertRedirect(route('subscription.show'));

        $newVersion = $this->subscription->versions()->latest('id')->firstOrFail();

        $this->assertNotSame($paidVersion->id, $newVersion->id);
        $this->assertTrue(
            $newVersion->end_date->isSameDay($paidVersion->end_date),
            'The paid period already purchased must be prorated, not extended.'
        );
    }

    #[Test]
    public function the_owner_can_activate_and_deactivate_a_module(): void
    {
        $this->assertNotContains('module_quotes', $this->subscription->getActiveModuleKeys());

        $this->actingAs($this->owner)
            ->patch(route('subscription.modules.toggle'), [
                'module_key' => 'module_quotes',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertContains('module_quotes', $this->subscription->fresh()->getActiveModuleKeys());

        $this->actingAs($this->owner)
            ->patch(route('subscription.modules.toggle'), [
                'module_key' => 'module_quotes',
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertNotContains('module_quotes', $this->subscription->fresh()->getActiveModuleKeys());
    }

    #[Test]
    public function a_user_with_a_role_cannot_toggle_modules(): void
    {
        $role = Role::create([
            'name' => 'cashier',
            'guard_name' => 'web',
            'branch_id' => $this->owner->branch_id,
        ]);
        $employee = User::factory()->create(['branch_id' => $this->owner->branch_id]);
        $employee->assignRole($role);

        $this->actingAs($employee)
            ->patchJson(route('subscription.modules.toggle'), [
                'module_key' => 'module_quotes',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertNotContains('module_quotes', $this->subscription->fresh()->getActiveModuleKeys());
    }

    #[Test]
    public function limits_cannot_be_toggled_as_modules(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('subscription.modules.toggle'), [
                'module_key' => 'limit_products',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('module_key');
    }

    private function freePlanVersion(): SubscriptionVersion
    {
        return $this->subscription->versions()->latest('id')->firstOrFail();
    }

    /**
     * A version covering a paid billing period, as any approved payment creates.
     */
    private function paidVersion(int $days): SubscriptionVersion
    {
        return new SubscriptionVersion([
            'start_date' => now(),
            'end_date' => now()->addDays($days),
        ]);
    }

    private function createPlanItems(): void
    {
        PlanItem::create([
            'key' => 'module_pos',
            'type' => 'module',
            'name' => 'Punto de Venta',
            'description' => 'Módulo principal',
            'monthly_price' => 130.0,
            'is_active' => true,
            'meta' => ['icon' => 'pi pi-shop'],
        ]);

        PlanItem::create([
            'key' => 'limit_products',
            'type' => 'limit',
            'name' => 'Productos',
            'description' => 'Límite de productos',
            'monthly_price' => 1.5,
            'is_active' => true,
            'meta' => ['quantity' => 100],
        ]);

        PlanItem::create([
            'key' => 'module_quotes',
            'type' => 'module',
            'name' => 'Cotizaciones',
            'description' => 'Cotizaciones que se convierten en ventas',
            'monthly_price' => 35.0,
            'is_active' => true,
            'meta' => ['icon' => 'pi pi-file-edit'],
        ]);
    }
}
