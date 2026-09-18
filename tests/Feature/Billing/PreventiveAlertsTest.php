<?php

namespace Tests\Feature\Billing;

use App\Enums\PacAccountStatus;
use App\Jobs\Billing\CheckPreventiveAlertsJob;
use App\Mail\PreventiveBillingAlertNotification;
use App\Models\Branch;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\PacAccount;
use App\Models\Subscription;
use App\Services\Billing\StampMovementService;
use App\Services\SW\SWUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 3 — T304/T305: the daily preventive alerts email low stamp balances
 * and CSD certificates about to expire (with cooldown dedup).
 */
class PreventiveAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const SUBSCRIBER_EMAIL = 'cliente@example.test';

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeEnvironment(): array
    {
        $subscription = Subscription::factory()->create([
            'contact_email'   => self::SUBSCRIBER_EMAIL,
            'commercial_name' => 'Negocio de prueba',
        ]);

        $branch = Branch::factory()->create(['subscription_id' => $subscription->id]);

        $account = PacAccount::factory()->shared()->create([
            'subscription_id' => $subscription->id,
            'status'          => PacAccountStatus::ACTIVE,
        ]);

        $profile = FiscalProfile::factory()->create([
            'subscription_id' => $subscription->id,
            'pac_account_id'  => $account->id,
        ]);

        return [$subscription, $branch, $profile];
    }

    private function runJob(): void
    {
        (new CheckPreventiveAlertsJob)->handle(app(SWUserService::class));
    }

    // ─────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_emails_low_balance_and_csd_alerts_once_per_cooldown(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';

        [, , $profile] = $this->makeEnvironment();

        // Wallet with 3 stamps — below the default threshold (5).
        app(StampMovementService::class)->grantWelcomeStamps($profile, 3);

        // CSD expiring in 15 days — one of the configured milestones.
        $profile->update(['valid_to' => now()->addDays(15)->toDateString()]);

        $this->runJob();

        Mail::assertSent(PreventiveBillingAlertNotification::class, function ($mail) {
            $types = collect($mail->issues)->pluck('type');

            return $mail->hasTo(self::SUBSCRIBER_EMAIL)
                && $types->contains('low_balance')
                && $types->contains('csd_expiring');
        });

        // Second daily run: the cooldown markers suppress repeated issues.
        $this->runJob();

        Mail::assertSentTimes(PreventiveBillingAlertNotification::class, 1);
    }

    #[Test]
    public function it_sends_nothing_when_there_are_no_issues(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';

        [, , $profile] = $this->makeEnvironment();

        // Healthy wallet and CSD far from expiring.
        app(StampMovementService::class)->grantWelcomeStamps($profile, 10);
        $profile->update(['valid_to' => now()->addDays(120)->toDateString()]);

        $this->runJob();

        Mail::assertNothingSent();
    }
}
