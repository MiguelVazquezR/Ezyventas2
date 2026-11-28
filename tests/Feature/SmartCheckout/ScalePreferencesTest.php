<?php

namespace Tests\Feature\SmartCheckout;

use App\Enums\PlanItemType;
use App\Http\Middleware\CheckOnboardingStatus;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureSubscriptionScope;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Role;
use App\Models\SmartPosSetting;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * On/off switches of the "Modo Caja Inteligente" (decision D2): the business can
 * enable or disable the scale from the Point of Sale at any time, so the
 * preferences endpoint must work while the feature is off and keep the saved
 * scale settings untouched.
 *
 * The scale carries no permission of its own: only the POS access rule protects
 * the routes, so any employee of the branch can flip the switches.
 */
class ScalePreferencesTest extends TestCase
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
        // users of these tests are regular ones.
        User::factory()->create();

        $this->branch = $this->makeTenant();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);

        Permission::create([
            'name' => 'pos.access',
            'description' => 'Acceder al Punto de Venta',
            'module' => 'Punto de Venta',
            'guard_name' => 'web',
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_enables_the_scale_so_the_settings_endpoint_stops_returning_403(): void
    {
        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertForbidden();

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Preferencias actualizadas.')
            ->assertJsonPath('scale_enabled', true)
            ->assertJsonPath('smart_checkout_enabled', false);

        $this->assertDatabaseHas('smartpos_settings', [
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
            'smart_checkout_enabled' => false,
        ]);

        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertOk()
            ->assertJsonPath('enabled', true);
    }

    #[Test]
    public function it_disables_the_scale_and_the_settings_endpoint_returns_403_again(): void
    {
        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk();

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => false])
            ->assertOk()
            ->assertJsonPath('scale_enabled', false)
            ->assertJsonPath('smart_checkout_enabled', false);

        $this->assertDatabaseHas('smartpos_settings', [
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => false,
        ]);

        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertForbidden();

        $this->getJson(route('pos.smart-checkout.scale.products'))
            ->assertForbidden();
    }

    #[Test]
    public function an_employee_with_pos_access_can_flip_the_switches(): void
    {
        $role = Role::create(['name' => 'Cajero', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo(Permission::where('name', 'pos.access')->firstOrFail());

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->assignRole($role);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // No permission about the scale is required, only POS access.
        $this->actingAs($employee)
            ->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk()
            ->assertJsonPath('scale_enabled', true);

        $this->assertDatabaseHas('smartpos_settings', [
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
        ]);
    }

    #[Test]
    public function it_never_touches_another_subscription(): void
    {
        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk();

        $otherBranch = $this->makeTenant();
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);

        $this->actingAs($otherUser)
            ->putJson(route('pos.smart-checkout.preferences.update'), [
                'scale_enabled' => false,
                'smart_checkout_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('scale_enabled', false)
            ->assertJsonPath('smart_checkout_enabled', true);

        // A keeps its own flags...
        $this->assertDatabaseHas('smartpos_settings', [
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
            'smart_checkout_enabled' => false,
        ]);

        // ...and B has its own row.
        $this->assertDatabaseHas('smartpos_settings', [
            'subscription_id' => $otherBranch->subscription_id,
            'scale_enabled' => false,
            'smart_checkout_enabled' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertOk()
            ->assertJsonPath('enabled', true);
    }

    #[Test]
    public function it_rejects_an_empty_or_non_boolean_body(): void
    {
        $this->putJson(route('pos.smart-checkout.preferences.update'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scale_enabled'])
            ->assertJsonPath('message', 'Indica al menos una preferencia: báscula o Modo Caja Inteligente.');

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => 'sí'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scale_enabled']);

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['smart_checkout_enabled' => 'depende'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['smart_checkout_enabled']);

        $this->assertDatabaseCount('smartpos_settings', 0);
    }

    #[Test]
    public function it_keeps_the_saved_scale_settings_when_a_flag_changes(): void
    {
        // Configure the scale first (needs the flag on).
        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk();

        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validScalePayload())
            ->assertOk()
            ->assertJsonPath('settings.active_profile_id', 'bascula-1');

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => false])
            ->assertOk();

        $row = SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->firstOrFail();
        $this->assertFalse($row->scale_enabled);
        // The switch does not wipe the configuration.
        $this->assertSame('bascula-1', $row->scale_settings['active_profile_id']);
        $this->assertCount(1, $row->scale_settings['profiles']);

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk();

        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertOk()
            ->assertJsonPath('settings.active_profile_id', 'bascula-1')
            ->assertJsonPath('settings.profiles.0.name', 'Báscula mostrador');
    }

    #[Test]
    public function the_pos_scale_prop_exposes_the_preference_flags(): void
    {
        // Off by default: the panel still learns that both switches exist.
        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scale.enabled', false)
                ->where('scale.settings', null)
                ->where('scale.preferences.scale', false)
                ->where('scale.preferences.smart_checkout', false)
            );

        $this->putJson(route('pos.smart-checkout.preferences.update'), ['scale_enabled' => true])
            ->assertOk();

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scale.enabled', true)
                ->where('scale.preferences.scale', true)
                ->where('scale.preferences.smart_checkout', false)
                ->where('scale.settings.unit', 'kg')
            );
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    /**
     * Branch of a fresh tenant with the Point of Sale module contracted. The
     * permissions of the feature only work when their module ("Punto de Venta")
     * is one of the contracted module names.
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

    /**
     * @return array<string, mixed>
     */
    private function validScalePayload(): array
    {
        return [
            'active_profile_id' => 'bascula-1',
            'profiles' => [
                [
                    'id' => 'bascula-1',
                    'name' => 'Báscula mostrador',
                    'baudRate' => 9600,
                    'dataBits' => 8,
                    'stopBits' => 1,
                    'parity' => 'none',
                    'terminator' => 'lf',
                    'pattern' => '/^(-?\d+(?:\.\d+)?)\s*(kg|g)/i',
                    'weightGroup' => 1,
                    'unitGroup' => 2,
                    'stableGroup' => 3,
                    'unit' => 'kg',
                    'notes' => 'Báscula de mostrador conectada por USB',
                ],
            ],
            'stability' => ['readings' => 7, 'tolerance_g' => 5],
            'unit' => 'kg',
            'driver' => 'manual',
        ];
    }
}
