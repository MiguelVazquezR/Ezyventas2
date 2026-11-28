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
 * Scale configuration endpoints of the POS (Phase 1 of "Modo Caja Inteligente").
 *
 * The scale carries no permission of its own: every user who can open the Point
 * of Sale (`pos.access`) may use and configure it. Only the business preference
 * (`smartpos_settings.scale_enabled`) can hide it.
 */
class ScaleSettingsTest extends TestCase
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
    public function the_pos_receives_the_scale_props_when_the_scale_is_enabled(): void
    {
        $this->enableScale();

        $this->get(route('pos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scale.enabled', true)
                ->where('scale.can_use', true)
                ->where('scale.can_configure', true)
                ->where('scale.settings.unit', 'kg')
                ->where('scale.settings.driver', 'simulated')
                ->where('scale.settings.stability.readings', 5)
                ->where('scale.settings.stability.tolerance_g', 3)
                ->where('scale.settings.profiles', [])
                ->where('scale.settings.active_profile_id', null)
                ->where('scale.preferences.scale', true)
                ->where('scale.preferences.smart_checkout', false)
            );
    }

    #[Test]
    public function it_returns_403_when_the_scale_is_disabled_for_the_business(): void
    {
        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertForbidden();
    }

    #[Test]
    public function it_returns_the_settings_of_the_business_when_the_scale_is_enabled(): void
    {
        $this->enableScale();

        $this->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('can_configure', true)
            ->assertJsonPath('settings.active_profile_id', null)
            ->assertJsonPath('settings.profiles', [])
            ->assertJsonPath('settings.stability.readings', 5)
            ->assertJsonPath('settings.stability.tolerance_g', 3)
            ->assertJsonPath('settings.unit', 'kg')
            ->assertJsonPath('settings.driver', 'simulated');
    }

    #[Test]
    public function it_stores_the_settings_and_never_touches_another_subscription(): void
    {
        $this->enableScale();

        // Second tenant of the same installation, with the scale enabled too.
        $otherBranch = $this->makeTenant();
        $otherSettings = SmartPosSetting::create([
            'subscription_id' => $otherBranch->subscription_id,
            'scale_enabled' => true,
        ]);

        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validPayload())
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('settings.active_profile_id', 'bascula-1')
            ->assertJsonPath('settings.profiles.0.name', 'Báscula mostrador')
            ->assertJsonPath('settings.stability.readings', 7)
            ->assertJsonPath('settings.stability.tolerance_g', 5)
            ->assertJsonPath('settings.driver', 'manual');

        $mine = SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->first();
        $this->assertSame('bascula-1', $mine->scale_settings['active_profile_id']);
        $this->assertCount(1, $mine->scale_settings['profiles']);
        $this->assertSame(9600, $mine->scale_settings['profiles'][0]['baudRate']);

        // Tenant B has not been touched by tenant A's update.
        $this->assertNull($otherSettings->fresh()->scale_settings);

        // Tenant B reads its own (default) settings...
        $this->actingAs(User::factory()->create(['branch_id' => $otherBranch->id]))
            ->getJson(route('pos.smart-checkout.scale.settings'))
            ->assertOk()
            ->assertJsonPath('settings.active_profile_id', null)
            ->assertJsonPath('settings.profiles', []);

        // ...and writes only its own row.
        $this->putJson(
            route('pos.smart-checkout.scale.settings.update'),
            $this->validPayload(['active_profile_id' => null, 'driver' => 'simulated']),
        )->assertOk();

        $this->assertSame('bascula-1', $mine->fresh()->scale_settings['active_profile_id']);
        $this->assertNull($otherSettings->fresh()->scale_settings['active_profile_id']);
        $this->assertCount(1, $otherSettings->fresh()->scale_settings['profiles']);
    }

    #[Test]
    public function it_rejects_invalid_profiles(): void
    {
        $this->enableScale();

        $profile = $this->validProfile();

        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validPayload([
            'profiles' => [
                // 0: the regex does not compile.
                array_merge($profile, ['id' => 'roto', 'pattern' => '/(kg/']),
                // 1: baud rate outside 1200..115200.
                array_merge($profile, ['id' => 'baudios', 'baudRate' => 999999]),
                // 2 and 3: duplicated ids (distinct).
                array_merge($profile, ['id' => 'repetido']),
                array_merge($profile, ['id' => 'repetido']),
            ],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'profiles.0.pattern',
                'profiles.1.baudRate',
                'profiles.3.id',
            ]);

        $this->assertNull(SmartPosSetting::where('subscription_id', $this->branch->subscription_id)->first()->scale_settings);
    }

    #[Test]
    public function it_rejects_more_than_ten_profiles(): void
    {
        $this->enableScale();

        $profile = $this->validProfile();
        $profiles = [];

        for ($i = 0; $i < 11; $i++) {
            $profiles[] = array_merge($profile, ['id' => 'perfil-'.$i]);
        }

        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validPayload([
            'profiles' => $profiles,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['profiles']);
    }

    #[Test]
    public function it_does_not_update_the_settings_while_the_scale_is_disabled(): void
    {
        $this->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('smartpos_settings', 0);
    }

    #[Test]
    public function any_pos_user_can_update_the_settings_without_a_specific_permission(): void
    {
        $this->enableScale();

        // A regular employee: the only permission involved is the POS one that
        // protects the whole module, never a permission about the scale.
        $role = Role::create(['name' => 'Cajero', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo(Permission::where('name', 'pos.access')->firstOrFail());

        $employee = User::factory()->create(['branch_id' => $this->branch->id]);
        $employee->assignRole($role);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($employee)
            ->putJson(route('pos.smart-checkout.scale.settings.update'), $this->validPayload())
            ->assertOk();

        $this->assertSame(
            'bascula-1',
            SmartPosSetting::where('subscription_id', $this->branch->subscription_id)
                ->first()
                ->scale_settings['active_profile_id'],
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

    private function enableScale(): SmartPosSetting
    {
        return SmartPosSetting::create([
            'subscription_id' => $this->branch->subscription_id,
            'scale_enabled' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validProfile(): array
    {
        return [
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
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'active_profile_id' => 'bascula-1',
            'profiles' => [$this->validProfile()],
            'stability' => ['readings' => 7, 'tolerance_g' => 5],
            'unit' => 'kg',
            'driver' => 'manual',
        ], $overrides);
    }
}
