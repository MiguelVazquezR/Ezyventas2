<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\ReferralCode;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload mirroring the fields sent by resources/js/Pages/Auth/Register.vue.
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Abarrotes La Esquina',
            'name' => 'Juan Pérez',
            'email' => 'juan.perez@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms' => true,
        ], $overrides);
    }

    private function firstSubscription(): Subscription
    {
        return Subscription::where('business_name', 'Abarrotes La Esquina')->firstOrFail();
    }

    /**
     * `subscription_versions.start_date` and `end_date` are DATETIME columns
     * (migration 2026_10_15_000001) because a MySQL TIMESTAMP cannot store a
     * date past 2038-01-19 03:14:07. Tests run on SQLite, which has no range
     * limit, so the column type is enforced by the migration and not here.
     */
    #[Test]
    public function it_gives_the_new_account_an_indefinite_plan(): void
    {
        $user = (app(CreateNewUser::class))->create($this->registrationPayload());

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseHas('users', ['email' => 'juan.perez@example.com']);

        $version = $this->firstSubscription()->versions()->firstOrFail();

        $this->assertTrue(
            $version->end_date->greaterThan(now()->addYears(40)),
            'The initial plan should be indefinite instead of a 30 day trial.'
        );
    }

    #[Test]
    public function it_keeps_the_subscription_active_right_after_registration(): void
    {
        (app(CreateNewUser::class))->create($this->registrationPayload());

        $this->assertNotNull($this->firstSubscription()->currentVersion());
    }

    #[Test]
    public function it_does_not_register_a_referral_usage_when_a_code_is_sent(): void
    {
        ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-ABC123',
            'is_active' => true,
        ]);

        (app(CreateNewUser::class))->create($this->registrationPayload(['referral_code' => 'EZY-ABC123']));

        $this->assertDatabaseCount('referral_usages', 0);
    }

    #[Test]
    public function it_ignores_an_unknown_referral_code_instead_of_failing(): void
    {
        $user = (app(CreateNewUser::class))->create($this->registrationPayload(['referral_code' => 'EZY-NOPE99']));

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseCount('referral_usages', 0);
    }

    #[Test]
    public function the_register_endpoint_creates_the_account(): void
    {
        $response = $this->post('/register', $this->registrationPayload());

        $response->assertRedirect();
        $this->assertAuthenticated();

        $this->assertNotNull($this->firstSubscription()->currentVersion());
    }

    #[Test]
    public function it_rejects_a_duplicated_business_name(): void
    {
        (app(CreateNewUser::class))->create($this->registrationPayload());

        $this->expectException(ValidationException::class);

        (app(CreateNewUser::class))->create($this->registrationPayload(['email' => 'otro@example.com']));
    }
}
