<?php

namespace Tests\Feature\Api\V1;

use App\Enums\PlanItemType;
use App\Models\Branch;
use App\Models\PlanItem;
use App\Models\Role;
use App\Models\SubscriptionItem;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Covers the mobile API phase 0: token authentication, access context,
 * JSON error shape and rate limited login.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->branch->subscription->update(['onboarding_completed_at' => now()]);

        // Active subscription version with the POS module contracted.
        $version = SubscriptionVersion::create([
            'subscription_id' => $this->branch->subscription_id,
            'start_date' => Carbon::yesterday(),
            'end_date' => Carbon::tomorrow(),
        ]);

        SubscriptionItem::create([
            'subscription_version_id' => $version->id,
            'item_key' => 'module_pos',
            'item_type' => 'module',
            'name' => 'Punto de Venta',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        PlanItem::create([
            'key' => 'module_pos',
            'type' => PlanItemType::MODULE,
            'name' => 'Punto de Venta',
            'monthly_price' => 0,
            'is_active' => true,
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'pos.access', 'module' => 'Punto de Venta']);
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
        ], $attributes));
    }

    private function employee(array $attributes = []): User
    {
        $user = $this->owner($attributes);
        $role = Role::create(['name' => 'Vendedor', 'branch_id' => $this->branch->id]);
        $role->givePermissionTo('pos.access');
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function it_issues_a_token_with_the_access_context(): void
    {
        $user = $this->owner();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
            'device_name' => 'Pixel 7',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'dueno@test.com')
            ->assertJsonPath('user.is_subscription_owner', true)
            ->assertJsonPath('user.branch.id', $this->branch->id)
            ->assertJsonPath('module_keys.0', 'module_pos')
            ->assertJsonPath('modules.0', 'Punto de Venta')
            ->assertJsonPath('active_session', null);

        $this->assertNotEmpty($response->json('token'));
        $this->assertContains('pos.access', $response->json('user.permissions'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 7',
        ]);
    }

    #[Test]
    public function it_rejects_invalid_credentials_with_a_spanish_message(): void
    {
        $this->owner();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'incorrecta',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales no coinciden con nuestros registros.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function it_rejects_a_deactivated_user(): void
    {
        $this->owner(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario está desactivado. Contacta al administrador.');
    }

    #[Test]
    public function it_blocks_employees_when_the_subscription_expired(): void
    {
        $this->employee();

        SubscriptionVersion::query()->update(['end_date' => Carbon::yesterday()]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'La suscripción de este negocio ha expirado.');
    }

    #[Test]
    public function it_returns_the_context_for_the_authenticated_device(): void
    {
        $this->owner();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
            'device_name' => 'Pixel 7',
        ])->json('token');

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.email', 'dueno@test.com')
            ->assertJsonPath('user.branch.id', $this->branch->id)
            ->assertJsonPath('module_keys.0', 'module_pos');

        $this->assertContains('pos.access', $response->json('user.permissions'));
    }

    #[Test]
    public function it_answers_json_when_the_token_is_missing(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    #[Test]
    public function it_answers_json_when_the_token_is_invalid(): void
    {
        $this->withToken('token-invalido')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');
    }

    #[Test]
    public function logout_revokes_only_the_current_token(): void
    {
        $user = $this->owner();

        $firstToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
            'device_name' => 'Pixel 7',
        ])->json('token');

        $secondToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'secreto123',
            'device_name' => 'Tablet',
        ])->json('token');

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($firstToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'Pixel 7']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'Tablet', 'tokenable_id' => $user->id]);

        // The revoked token can no longer be used.
        // forgetGuards() clears the guard cached by the testing kernel between requests.
        $this->app['auth']->forgetGuards();

        $this->withToken($firstToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // The remaining token keeps working.
        $this->withToken($secondToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    #[Test]
    public function it_validates_required_fields_in_spanish(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Escribe tu correo electrónico.')
            ->assertJsonPath('errors.password.0', 'Escribe tu contraseña.');
    }

    #[Test]
    public function it_throttles_repeated_login_attempts(): void
    {
        $this->owner();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'dueno@test.com',
                'password' => 'incorrecta',
            ])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dueno@test.com',
            'password' => 'incorrecta',
        ])->assertStatus(429);
    }
}

