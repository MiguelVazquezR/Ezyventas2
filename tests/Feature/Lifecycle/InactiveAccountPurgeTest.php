<?php

namespace Tests\Feature\Lifecycle;

use App\Jobs\Lifecycle\PurgeInactiveAccountsJob;
use App\Jobs\Lifecycle\SendAccountLifecycleWarningsJob;
use App\Mail\InactiveAccountWarningMail;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Lifecycle\AccountLifecycleService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Account hibernation: inactive accounts are warned 7 days before their
 * business data is purged (90 days without a sign-in), and signing in is
 * always enough to keep everything.
 */
class InactiveAccountPurgeTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function enableLifecycle(): void
    {
        config(['account_lifecycle.inactivity.enabled' => true]);
    }

    /**
     * Build a tenant with one row in every entity the purge must remove.
     *
     * @return array<string, mixed>
     */
    private function makeTenant(?Carbon $lastLoginAt): array
    {
        $subscription = Subscription::factory()->create([
            'commercial_name' => 'Negocio de prueba',
            'contact_email' => 'contacto@example.test',
        ]);

        $branch = Branch::factory()->create([
            'subscription_id' => $subscription->id,
            'is_main' => true,
        ]);

        $owner = User::factory()->create([
            'branch_id' => $branch->id,
            'last_login_at' => $lastLoginAt,
        ]);

        $staff = User::factory()->create(['branch_id' => $branch->id]);

        $category = Category::factory()->create(['subscription_id' => $subscription->id]);

        $product = Product::factory()->create(['branch_id' => $branch->id]);

        $service = Service::factory()->create([
            'branch_id' => $branch->id,
            'category_id' => $category->id,
        ]);

        $customer = Customer::factory()->create(['branch_id' => $branch->id]);

        $transaction = Transaction::factory()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'cash_register_session_id' => null,
        ]);

        return compact(
            'subscription',
            'branch',
            'owner',
            'staff',
            'category',
            'product',
            'service',
            'customer',
            'transaction',
        );
    }

    private function runWarningJob(): void
    {
        $this->app->call([new SendAccountLifecycleWarningsJob, 'handle']);
    }

    private function runPurgeJob(): void
    {
        $this->app->call([new PurgeInactiveAccountsJob, 'handle']);
    }

    // ─────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_warns_the_owner_once_before_the_purge(): void
    {
        $this->enableLifecycle();
        Mail::fake();

        ['subscription' => $subscription, 'owner' => $owner] = $this->makeTenant(now()->subDays(84));

        $this->runWarningJob();

        Mail::assertSent(InactiveAccountWarningMail::class, function (InactiveAccountWarningMail $mail) use ($owner) {
            return $mail->hasTo($owner->email)
                && $mail->daysUntilPurge === 6
                && $mail->subscriptionName === 'Negocio de prueba';
        });

        $this->assertNotNull($subscription->fresh()->inactivity_warned_at);

        // The marker prevents a second warning on the next daily run.
        $this->runWarningJob();

        Mail::assertSentTimes(InactiveAccountWarningMail::class, 1);
    }

    #[Test]
    public function it_purges_the_business_data_and_keeps_the_account(): void
    {
        $this->enableLifecycle();

        $tenant = $this->makeTenant(now()->subDays(91));
        $untouched = $this->makeTenant(now());

        $this->runPurgeJob();

        // Business data is gone.
        $this->assertDatabaseMissing('products', ['id' => $tenant['product']->id]);
        $this->assertDatabaseMissing('services', ['id' => $tenant['service']->id]);
        $this->assertDatabaseMissing('customers', ['id' => $tenant['customer']->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $tenant['transaction']->id]);

        // Secondary users are removed, the owner can still sign in.
        $this->assertDatabaseMissing('users', ['id' => $tenant['staff']->id]);
        $this->assertDatabaseHas('users', ['id' => $tenant['owner']->id]);

        // The account itself survives.
        $this->assertDatabaseHas('subscriptions', ['id' => $tenant['subscription']->id]);
        $this->assertDatabaseHas('branches', ['id' => $tenant['branch']->id]);
        $this->assertNotNull($tenant['subscription']->fresh()->data_purged_at);

        // Another tenant is never touched.
        $this->assertDatabaseHas('products', ['id' => $untouched['product']->id]);
        $this->assertDatabaseHas('customers', ['id' => $untouched['customer']->id]);
        $this->assertDatabaseHas('users', ['id' => $untouched['staff']->id]);
        $this->assertNull($untouched['subscription']->fresh()->data_purged_at);

        // A second run is a no-op instead of an error.
        $this->runPurgeJob();

        $this->assertDatabaseHas('users', ['id' => $tenant['owner']->id]);
    }

    #[Test]
    public function it_uses_the_creation_date_when_nobody_ever_signed_in(): void
    {
        $this->enableLifecycle();

        $tenant = $this->makeTenant(null);
        $tenant['subscription']->forceFill(['created_at' => now()->subDays(100)])->saveQuietly();

        $this->runPurgeJob();

        $this->assertDatabaseMissing('products', ['id' => $tenant['product']->id]);
        $this->assertNotNull($tenant['subscription']->fresh()->data_purged_at);
    }

    #[Test]
    public function it_reactivates_the_account_when_the_owner_signs_in(): void
    {
        $tenant = $this->makeTenant(now()->subDays(95));

        $tenant['subscription']->forceFill([
            'inactivity_warned_at' => now()->subDays(8),
            'data_purged_at' => now()->subDays(1),
        ])->saveQuietly();

        event(new Login('web', $tenant['owner'], false));

        $subscription = $tenant['subscription']->fresh();

        $this->assertNull($subscription->inactivity_warned_at);
        $this->assertNull($subscription->data_purged_at);
        $this->assertNotNull($tenant['owner']->fresh()->last_login_at);
    }

    #[Test]
    public function it_does_nothing_when_the_feature_is_disabled(): void
    {
        config(['account_lifecycle.inactivity.enabled' => false]);
        Mail::fake();

        $tenant = $this->makeTenant(now()->subDays(120));

        $this->runWarningJob();
        $this->runPurgeJob();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('products', ['id' => $tenant['product']->id]);
        $this->assertDatabaseHas('customers', ['id' => $tenant['customer']->id]);
        $this->assertNull($tenant['subscription']->fresh()->data_purged_at);
    }

    #[Test]
    public function it_renders_the_warning_email_in_spanish(): void
    {
        $mail = new InactiveAccountWarningMail('Negocio de prueba', '24/10/2026', 6);

        $html = $mail->render();

        $this->assertStringContainsString('Tu cuenta se eliminará por inactividad', $html);
        $this->assertStringContainsString('24/10/2026', $html);
        $this->assertStringContainsString('6 días', $html);
    }

    #[Test]
    public function it_exposes_the_days_left_before_the_purge(): void
    {
        $tenant = $this->makeTenant(now()->subDays(30));

        $lifecycle = app(AccountLifecycleService::class);

        $this->assertSame(60, $lifecycle->daysUntilPurge($tenant['subscription']));
        $this->assertTrue($lifecycle->resolvePrimaryUser($tenant['subscription'])->is($tenant['owner']));
    }
}
