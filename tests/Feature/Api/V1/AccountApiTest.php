<?php

namespace Tests\Feature\Api\V1;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\Branch;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Api\V1\Concerns\BuildsMobileApiContext;
use Tests\TestCase;

/**
 * Covers the account block of the mobile API phase 4: branch switch,
 * notifications, support, profile and subscription.
 */
class AccountApiTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileApiContext;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMobileApiContext();

        $this->owner = $this->ownerUser();
        $this->token = $this->tokenFor($this->owner);
    }

    #[Test]
    public function it_lists_the_branches_the_user_can_switch_to(): void
    {
        $second = Branch::factory()->create([
            'subscription_id' => $this->subscription->id,
            'name' => 'Sucursal Centro',
        ]);
        Branch::factory()->create(); // from another business

        $this->withToken($this->token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(2, 'available_branches')
            // Order independent: both branches are listed and the current one is flagged.
            ->assertJsonFragment(['id' => $this->branch->id, 'name' => $this->branch->name, 'is_current' => true])
            ->assertJsonFragment(['id' => $second->id, 'name' => 'Sucursal Centro', 'is_current' => false]);
    }

    #[Test]
    public function it_switches_the_branch_and_returns_the_new_context(): void
    {
        $second = Branch::factory()->create([
            'subscription_id' => $this->subscription->id,
            'name' => 'Sucursal Centro',
        ]);

        $this->withToken($this->token)
            ->putJson('/api/v1/branch/switch/' . $second->id)
            ->assertOk()
            ->assertJsonPath('branch.id', $second->id)
            ->assertJsonPath('branch.name', 'Sucursal Centro')
            ->assertJsonPath('message', 'Cambiado a la sucursal: Sucursal Centro')
            ->assertJsonPath('context.user.branch_id', $second->id)
            ->assertJsonPath('context.user.branch.name', 'Sucursal Centro');

        $this->assertEquals($second->id, $this->owner->fresh()->branch_id);
    }

    #[Test]
    public function it_rejects_switching_to_a_branch_of_another_subscription(): void
    {
        $foreign = Branch::factory()->create();

        $this->withToken($this->token)
            ->putJson('/api/v1/branch/switch/' . $foreign->id)
            ->assertStatus(403)
            ->assertJsonPath('code', 'branch_out_of_scope')
            ->assertJsonPath('message', 'No tienes permiso para cambiar a esta sucursal.');

        $this->assertEquals($this->branch->id, $this->owner->fresh()->branch_id);
    }

    #[Test]
    public function it_returns_the_notification_counters(): void
    {
        // A layaway that expires in two days.
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->owner->id,
            'status' => \App\Enums\TransactionStatus::ON_LAYAWAY,
            'layaway_expiration_date' => now()->addDays(2),
        ]);

        $this->withToken($this->token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('expiring_debts', 1)
            ->assertJsonPath('upcoming_deliveries', 0)
            ->assertJsonPath('pending_orders', 0)
            ->assertJsonStructure(['expiring_debts', 'upcoming_deliveries', 'unread_updates', 'pending_orders', 'total']);

        // Without access to the sales module every counter comes as zero.
        $employee = $this->employeeUser(['customers.access']);

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('expiring_debts', 0)
            ->assertJsonPath('total', 0);
    }

    #[Test]
    public function it_returns_the_support_content(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/support')
            ->assertOk()
            ->assertJsonPath('title', 'Centro de soporte')
            ->assertJsonPath('subtitle', 'Estamos aquí para ayudarte')
            ->assertJsonCount(2, 'schedule')
            ->assertJsonPath('channels.0.type', 'email')
            ->assertJsonPath('channels.0.url', 'mailto:notificaciones@ezyventas.com')
            ->assertJsonPath('channels.1.type', 'whatsapp')
            ->assertJsonCount(4, 'help_topics')
            ->assertJsonPath('help_topics.0.id', 'steps');

        $this->assertStringContainsString('/centro-ayuda', $this->getJson('/api/v1/support')->json('help_center_url') ?? '');
    }

    #[Test]
    public function it_returns_the_profile_and_updates_the_personal_data(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $this->owner->id)
            ->assertJsonPath('user.email', $this->owner->email)
            ->assertJsonPath('user.has_photo', false)
            ->assertJsonPath('context.user.id', $this->owner->id);

        // Same email: nothing to verify again.
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'María López',
                'email' => $this->owner->email,
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'María López')
            ->assertJsonPath('email_verification_sent', false)
            ->assertJsonPath('message', 'Tus datos se guardaron.');

        // New email: the account has to be verified again.
        $this->withToken($this->token)
            ->putJson('/api/v1/profile', [
                'name' => 'María López',
                'email' => 'nuevo-correo@test.com',
            ])
            ->assertOk()
            ->assertJsonPath('email_verification_sent', true)
            ->assertJsonPath('user.email_verified_at', null);

        $this->assertEquals('nuevo-correo@test.com', $this->owner->fresh()->email);
    }

    #[Test]
    public function it_changes_the_password_and_rejects_a_wrong_current_one(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'incorrecta',
                'password' => 'nuevaClave123',
                'password_confirmation' => 'nuevaClave123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_current_password')
            ->assertJsonPath('message', 'La contraseña actual no es correcta.');

        $this->withToken($this->token)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'secreto123',
                'password' => 'nuevaClave123',
                'password_confirmation' => 'diferente123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'password' => 'La confirmación de la contraseña no coincide.',
            ]);

        $this->withToken($this->token)
            ->putJson('/api/v1/profile/password', [
                'current_password' => 'secreto123',
                'password' => 'nuevaClave123',
                'password_confirmation' => 'nuevaClave123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Tu contraseña se actualizó.');

        $this->assertTrue(Hash::check('nuevaClave123', $this->owner->fresh()->password));
    }

    #[Test]
    public function it_closes_the_other_sessions_but_keeps_the_current_one(): void
    {
        // A second device signs in with the same user.
        $otherToken = $this->tokenFor($this->owner);
        $this->assertEquals(2, PersonalAccessToken::count());

        $this->withToken($this->token)
            ->postJson('/api/v1/profile/logout-other-devices', ['password' => 'secreto123'])
            ->assertOk()
            ->assertJsonPath('message', 'Se cerraron las demás sesiones.');

        $this->assertEquals(1, PersonalAccessToken::count());
        // The phone that asked keeps working.
        $this->withToken($this->token)->getJson('/api/v1/auth/me')->assertOk();

        // The other device's token is gone.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) explode('|', $otherToken)[0],
        ]);
    }

    #[Test]
    public function it_rejects_closing_the_other_sessions_with_a_wrong_password(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/profile/logout-other-devices', ['password' => 'incorrecta'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_current_password');
    }

    #[Test]
    public function it_returns_the_subscription_screen_to_the_owner_only(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/subscription');

        $response->assertOk()
            ->assertJsonPath('subscription.id', $this->subscription->id)
            ->assertJsonPath('subscription.commercial_name', $this->subscription->commercial_name)
            ->assertJsonPath('usage.branches', 1)
            // The subscription of the tests expires tomorrow, so it warns.
            ->assertJsonPath('status_data.label', 'Por vencer')
            ->assertJsonPath('status_data.is_expired', false)
            ->assertJsonPath('status_data.days_left', 1)
            ->assertJsonPath('plan.modules.0.key', 'module_pos')
            ->assertJsonStructure([
                'subscription', 'plan', 'usage', 'status_data',
                'pending_payment', 'last_rejected_payment', 'fiscal_document_url', 'history',
            ]);

        // The team does not see the subscription of the business.
        $employee = $this->employeeUser(['pos.access']);

        $this->withToken($this->tokenFor($employee))
            ->getJson('/api/v1/subscription')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Tu usuario no tiene permiso para esta acción.');
    }

    #[Test]
    public function it_updates_the_subscription_data_and_requests_an_invoice(): void
    {
        $this->withToken($this->token)
            ->putJson('/api/v1/subscription', [
                'commercial_name' => 'Refaccionaria López',
                'business_name' => 'López Servicios S.A. de C.V.',
                'contact_phone' => '4771234567',
                'address' => 'Av. Hidalgo 120, León',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Los datos de la suscripción se guardaron.')
            ->assertJsonPath('subscription.commercial_name', 'Refaccionaria López');

        // Invoice of an approved payment: requested once, then reported as done.
        $approved = $this->subscriptionPayment(SubscriptionPaymentStatus::APPROVED);

        $this->withToken($this->token)
            ->postJson('/api/v1/subscription/payments/' . $approved->id . '/request-invoice')
            ->assertOk()
            ->assertJsonPath('message', 'Factura solicitada. Nos pondremos en contacto pronto.');

        $this->withToken($this->token)
            ->postJson('/api/v1/subscription/payments/' . $approved->id . '/request-invoice')
            ->assertOk()
            ->assertJsonPath('message', 'Esta factura ya ha sido solicitada o generada.');

        // A payment that is not approved cannot be invoiced.
        $pending = $this->subscriptionPayment(SubscriptionPaymentStatus::PENDING);

        $this->withToken($this->token)
            ->postJson('/api/v1/subscription/payments/' . $pending->id . '/request-invoice')
            ->assertStatus(403)
            ->assertJsonPath('code', 'payment_not_approved');
    }

    #[Test]
    public function it_requires_a_token(): void
    {
        $this->getJson('/api/v1/profile')
            ->assertStatus(401)
            ->assertJsonPath('message', 'No autenticado.');

        $this->getJson('/api/v1/subscription')->assertStatus(401);
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    /**
     * Payment of the subscription used by the invoice tests.
     */
    private function subscriptionPayment(SubscriptionPaymentStatus $status): SubscriptionPayment
    {
        $version = SubscriptionVersion::where('subscription_id', $this->subscription->id)->firstOrFail();

        return SubscriptionPayment::create([
            'subscription_version_id' => $version->id,
            'amount' => 599,
            'payment_method' => 'transferencia',
            'status' => $status,
            'payment_details' => ['folio' => 'PAGO-004'],
        ]);
    }
}
