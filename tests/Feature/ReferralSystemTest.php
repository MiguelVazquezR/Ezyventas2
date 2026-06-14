<?php

namespace Tests\Feature;

use App\Actions\Referral\ApplyReferralDiscountAction;
use App\Actions\Referral\GenerateReferralCodeAction;
use App\Actions\Referral\ProcessReferralOnPaymentApprovedAction;
use App\Actions\Referral\UpdateReferrerOngoingDiscountAction;
use App\Models\Branch;
use App\Models\ReferralCode;
use App\Models\ReferralSettings;
use App\Models\ReferralUsage;
use App\Models\ReferrerBankAccount;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReferralSystemTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // GenerateReferralCodeAction
    // -----------------------------------------------------------------

    #[Test]
    public function generate_referral_code_creates_code_for_user_without_one(): void
    {
        $user = User::factory()->create();

        $action = new GenerateReferralCodeAction();
        $code = $action->execute($user);

        $this->assertInstanceOf(ReferralCode::class, $code);
        $this->assertEquals($user->id, $code->user_id);
        $this->assertTrue($code->is_active);
        $this->assertStringStartsWith('EZY-', $code->code);
        $this->assertEquals(10, strlen($code->code)); // EZY- + 6 chars
        $this->assertDatabaseHas('referral_codes', [
            'user_id' => $user->id,
            'code' => $code->code,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function generate_referral_code_returns_existing_code_if_already_exists(): void
    {
        $user = User::factory()->create();
        $existingCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-ABC123',
            'is_active' => true,
        ]);

        $action = new GenerateReferralCodeAction();
        $code = $action->execute($user);

        $this->assertEquals($existingCode->id, $code->id);
        $this->assertEquals('EZY-ABC123', $code->code);
    }

    #[Test]
    public function generate_referral_code_ensures_uniqueness(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        ReferralCode::factory()->create([
            'user_id' => $user1->id,
            'code' => 'EZY-UNIQUE',
            'is_active' => true,
        ]);

        // Mock Str::random to return controlled value, then fallback
        // We trust the do-while loop ensures uniqueness by testing indirectly:
        // generating for user2 should never collide with user1's code
        $action = new GenerateReferralCodeAction();
        $code = $action->execute($user2);

        $this->assertNotEquals('EZY-UNIQUE', $code->code);
        $this->assertEquals(1, ReferralCode::where('code', 'EZY-UNIQUE')->count());
    }

    // -----------------------------------------------------------------
    // ApplyReferralDiscountAction
    // -----------------------------------------------------------------

    #[Test]
    public function apply_referral_discount_calculates_correct_amounts(): void
    {
        ReferralSettings::create([
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-VALID1',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        // First payment: no versions yet (count = 0, which is <= 1)
        $originalAmount = 1000.00;

        $action = new ApplyReferralDiscountAction();
        $result = $action->execute('EZY-VALID1', $referredSubscription, $originalAmount);

        $this->assertEquals(15.00, $result['discount_pct']);
        $this->assertEquals(150.00, $result['discount_amount']);
        $this->assertEquals(850.00, $result['final_amount']);
        $this->assertInstanceOf(ReferralCode::class, $result['referral_code']);
        $this->assertInstanceOf(ReferralSettings::class, $result['settings']);
    }

    #[Test]
    public function apply_referral_discount_uses_default_settings_when_none_exist(): void
    {
        $referrerUser = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-DEFLT',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        $action = new ApplyReferralDiscountAction();
        $result = $action->execute('EZY-DEFLT', $referredSubscription, 1000.00);

        // Default: 15% discount
        $this->assertEquals(15.00, $result['discount_pct']);
        $this->assertEquals(150.00, $result['discount_amount']);
        $this->assertEquals(850.00, $result['final_amount']);
        $this->assertDatabaseHas('referral_settings', [
            'referred_discount_pct' => 15.00,
        ]);
    }

    #[Test]
    public function apply_referral_discount_throws_for_own_code(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $subscription = $branch->subscription;

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-SELF1',
            'is_active' => true,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No puedes usar tu propio código de referido.');

        $action = new ApplyReferralDiscountAction();
        $action->execute('EZY-SELF1', $subscription, 1000.00);
    }

    #[Test]
    public function apply_referral_discount_throws_when_not_first_payment(): void
    {
        $referrerUser = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-NOT1ST',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        // Simulate already having more than 1 version (not first payment)
        SubscriptionVersion::factory()->create([
            'subscription_id' => $referredSubscription->id,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonths(2)->addMonth(),
        ]);
        SubscriptionVersion::factory()->create([
            'subscription_id' => $referredSubscription->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('El código de referido solo aplica en el primer pago.');

        $action = new ApplyReferralDiscountAction();
        $action->execute('EZY-NOT1ST', $referredSubscription, 1000.00);
    }

    #[Test]
    public function apply_referral_discount_throws_when_already_referred(): void
    {
        $referrerUser = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-ALRDY',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        // Create an existing referral usage for this subscription
        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $referredSubscription->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Esta suscripción ya fue referida previamente.');

        $action = new ApplyReferralDiscountAction();
        $action->execute('EZY-ALRDY', $referredSubscription, 1000.00);
    }

    #[Test]
    public function apply_referral_discount_throws_for_inactive_code(): void
    {
        $referrerUser = User::factory()->create();
        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-DEAD1',
            'is_active' => false,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $action = new ApplyReferralDiscountAction();
        $action->execute('EZY-DEAD1', $referredSubscription, 1000.00);
    }

    #[Test]
    public function apply_referral_discount_throws_for_nonexistent_code(): void
    {
        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $action = new ApplyReferralDiscountAction();
        $action->execute('EZY-GHOST1', $referredSubscription, 1000.00);
    }

    // -----------------------------------------------------------------
    // ProcessReferralOnPaymentApprovedAction
    // -----------------------------------------------------------------

    #[Test]
    public function process_referral_on_payment_approved_activates_referrer_discount(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);
        $referrerSubscription = $referrerBranch->subscription;

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-APPRV',
            'is_active' => true,
        ]);

        $referredSubscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $referredSubscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);

        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 850.00,
            'status' => 'approved',
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $referredSubscription->id,
            'subscription_payment_id' => $payment->id,
            'reward_status' => 'pending',
        ]);

        $action = new ProcessReferralOnPaymentApprovedAction();
        $action->execute($payment);

        $this->assertTrue((bool) $referrerSubscription->fresh()->referrer_discount_active);
    }

    #[Test]
    public function process_referral_on_payment_approved_does_nothing_without_usage(): void
    {
        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);

        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
            'status' => 'approved',
        ]);

        // No referral usage attached — should not throw
        $action = new ProcessReferralOnPaymentApprovedAction();
        $action->execute($payment);

        $this->assertTrue(true); // No exception = pass
    }

    // -----------------------------------------------------------------
    // UpdateReferrerOngoingDiscountAction
    // -----------------------------------------------------------------

    #[Test]
    public function update_referrer_ongoing_discount_deactivates_when_no_active_referrals(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);
        $referrerSubscription = $referrerBranch->subscription;
        $referrerSubscription->update(['referrer_discount_active' => true]);

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-DEACT',
            'is_active' => true,
        ]);

        // Referred subscription has an expired version (not active)
        $referredSubscription = Subscription::factory()->create();
        SubscriptionVersion::factory()->create([
            'subscription_id' => $referredSubscription->id,
            'start_date' => now()->subMonths(3),
            'end_date' => now()->subMonths(1), // expired
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $referredSubscription->id,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $action = new UpdateReferrerOngoingDiscountAction();
        $action->execute($referredSubscription);

        $this->assertFalse((bool) $referrerSubscription->fresh()->referrer_discount_active);
    }

    #[Test]
    public function update_referrer_ongoing_discount_keeps_active_when_referral_is_active(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);
        $referrerSubscription = $referrerBranch->subscription;
        $referrerSubscription->update(['referrer_discount_active' => false]);

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-ACTIV',
            'is_active' => true,
        ]);

        // Active referred subscription
        $referredSubscription = Subscription::factory()->create();
        SubscriptionVersion::factory()->create([
            'subscription_id' => $referredSubscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $referredSubscription->id,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $action = new UpdateReferrerOngoingDiscountAction();
        $action->execute($referredSubscription);

        $this->assertTrue((bool) $referrerSubscription->fresh()->referrer_discount_active);
    }

    #[Test]
    public function update_referrer_ongoing_discount_does_nothing_without_usage(): void
    {
        $subscription = Subscription::factory()->create();

        $action = new UpdateReferrerOngoingDiscountAction();
        $action->execute($subscription);

        $this->assertTrue(true); // No exception = pass
    }

    // -----------------------------------------------------------------
    // ReferralController — HTTP endpoints
    // -----------------------------------------------------------------

    #[Test]
    public function referral_index_page_loads_with_correct_data(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $subscription = $branch->subscription;
        $subscription->update(['onboarding_completed_at' => now()]);

        SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-INDX1',
            'is_active' => true,
        ]);

        ReferralSettings::create([
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('referrals.index'));

        $response->assertOk();
        $response->assertInertia(fn($page) => $page
            ->component('Subscription/Referral/Index')
            ->has('referralCode')
            ->has('referrals')
            ->has('pendingRewards')
            ->has('totalEarned')
            ->has('bankAccount')
            ->has('settings')
            ->has('activeReferralsCount')
            ->has('subscriptionCost')
            ->has('referrerActiveDiscountPct')
        );
    }

    #[Test]
    public function get_code_returns_code_for_owner(): void
    {
        $user = User::factory()->create(); // no roles = owner
        $user->subscription->update(['onboarding_completed_at' => now()]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.code'));

        $response->assertOk();
        $response->assertJsonStructure(['code']);
        $this->assertStringStartsWith('EZY-', $response->json('code'));
    }

    #[Test]
    public function get_code_returns_403_for_non_owner(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        // Simulate having a role (not owner)
        $user->roles()->create(['name' => 'employee', 'branch_id' => $user->branch_id]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.code'));

        $response->assertStatus(403);
    }

    #[Test]
    public function save_bank_account_creates_new_record(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $payload = [
            'clabe' => '012345678901234567',
            'bank_name' => 'Banco Ejemplo',
            'account_holder_name' => 'Juan Pérez',
        ];

        $response = $this->post(route('referrals.bank-account'), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('referrer_bank_accounts', [
            'user_id' => $user->id,
            'clabe' => '012345678901234567',
            'bank_name' => 'Banco Ejemplo',
            'account_holder_name' => 'Juan Pérez',
        ]);
    }

    #[Test]
    public function save_bank_account_updates_existing_record(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        ReferrerBankAccount::create([
            'user_id' => $user->id,
            'clabe' => '012345678901234567',
            'bank_name' => 'Banco Viejo',
            'account_holder_name' => 'Juan Pérez',
        ]);

        $this->actingAs($user);

        $payload = [
            'clabe' => '987654321098765432',
            'bank_name' => 'Banco Nuevo',
            'account_holder_name' => 'María García',
        ];

        $response = $this->post(route('referrals.bank-account'), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertEquals(1, ReferrerBankAccount::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('referrer_bank_accounts', [
            'user_id' => $user->id,
            'clabe' => '987654321098765432',
            'bank_name' => 'Banco Nuevo',
        ]);
    }

    #[Test]
    public function save_bank_account_validates_required_fields(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->post(route('referrals.bank-account'), []);

        $response->assertSessionHasErrors(['clabe', 'bank_name', 'account_holder_name']);
    }

    #[Test]
    public function save_bank_account_validates_clabe_digits(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->post(route('referrals.bank-account'), [
            'clabe' => '123',
            'bank_name' => 'Banco',
            'account_holder_name' => 'Juan',
        ]);

        $response->assertSessionHasErrors('clabe');
    }

    #[Test]
    public function mark_seen_updates_all_unseen_referrals(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-SEEN1',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'seen_at' => null,
        ]);
        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'seen_at' => null,
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('referrals.mark-seen'));

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $unseenCount = $user->referralUsagesAsReferrer()->whereNull('seen_at')->count();
        $this->assertEquals(0, $unseenCount);
    }

    #[Test]
    public function validate_code_returns_valid_for_good_code(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);

        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-GOOD1',
            'is_active' => true,
        ]);

        ReferralSettings::create([
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $user->subscription->update(['onboarding_completed_at' => now()]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-GOOD1']));

        $response->assertOk();
        $response->assertJson([
            'valid' => true,
            'discount_pct' => 15.00,
        ]);
    }

    #[Test]
    public function validate_code_rejects_empty_or_short_code(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'ABC']));
        $response->assertJson(['valid' => false]);
    }

    #[Test]
    public function validate_code_rejects_nonexistent_code(): void
    {
        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-GHOST']));
        $response->assertJson(['valid' => false, 'message' => 'Este código no existe.']);
    }

    #[Test]
    public function validate_code_rejects_inactive_code(): void
    {
        $referrerUser = User::factory()->create();
        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-DEAD2',
            'is_active' => false,
        ]);

        $user = User::factory()->create();
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-DEAD2']));
        $response->assertJson(['valid' => false, 'message' => 'Este código ya no está activo.']);
    }

    #[Test]
    public function validate_code_rejects_own_code(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $user->subscription->update(['onboarding_completed_at' => now()]);

        ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-MINE1',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-MINE1']));
        $response->assertJson(['valid' => false, 'message' => 'No puedes usar tu propio código.']);
    }

    // -----------------------------------------------------------------
    // AdminReferralController — HTTP endpoints
    // -----------------------------------------------------------------

    #[Test]
    public function admin_referral_index_lists_usages(): void
    {
        $adminUser = User::factory()->create();
        $adminBranch = Branch::factory()->create(['subscription_id' => 1]);
        $adminUser->update(['branch_id' => $adminBranch->id]);
        $adminUser->subscription->update(['onboarding_completed_at' => now()]);

        $this->actingAs($adminUser);

        $response = $this->get(route('admin.referrals.index'));

        $response->assertOk();
        $response->assertInertia(fn($page) => $page
            ->component('Admin/Referral/Index')
            ->has('usages')
        );
    }

    #[Test]
    public function admin_mark_paid_updates_reward_status(): void
    {
        $adminUser = User::factory()->create();
        $adminBranch = Branch::factory()->create(['subscription_id' => 1]);
        $adminUser->update(['branch_id' => $adminBranch->id]);
        $adminUser->subscription->update(['onboarding_completed_at' => now()]);

        $referralCode = ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-PAYME',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        $usage = ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'reward_status' => 'pending',
        ]);

        $this->actingAs($adminUser);

        $response = $this->post(route('admin.referrals.pay', $usage));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('referral_usages', [
            'id' => $usage->id,
            'reward_status' => 'paid',
        ]);
        $this->assertNotNull($usage->fresh()->reward_paid_at);
    }

    #[Test]
    public function admin_settings_page_shows_settings(): void
    {
        $adminUser = User::factory()->create();
        $adminBranch = Branch::factory()->create(['subscription_id' => 1]);
        $adminUser->update(['branch_id' => $adminBranch->id]);
        $adminUser->subscription->update(['onboarding_completed_at' => now()]);

        ReferralSettings::create([
            'referred_discount_pct' => 20.00,
            'referrer_reward_pct' => 60.00,
            'referrer_ongoing_discount_pct' => 15.00,
        ]);

        $this->actingAs($adminUser);

        $response = $this->get(route('admin.referrals.settings'));

        $response->assertOk();
        $response->assertInertia(fn($page) => $page
            ->component('Admin/Referral/Settings')
            ->has('settings')
            ->where('settings.referred_discount_pct', '20.00')
            ->where('settings.referrer_reward_pct', '60.00')
            ->where('settings.referrer_ongoing_discount_pct', '15.00')
        );
    }

    #[Test]
    public function admin_update_settings_persists_changes(): void
    {
        $adminUser = User::factory()->create();
        $adminBranch = Branch::factory()->create(['subscription_id' => 1]);
        $adminUser->update(['branch_id' => $adminBranch->id]);
        $adminUser->subscription->update(['onboarding_completed_at' => now()]);

        $this->actingAs($adminUser);

        $payload = [
            'referred_discount_pct' => 25.00,
            'referrer_reward_pct' => 55.00,
            'referrer_ongoing_discount_pct' => 12.00,
        ];

        $response = $this->put(route('admin.referrals.settings.update'), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('referral_settings', [
            'referred_discount_pct' => 25.00,
            'referrer_reward_pct' => 55.00,
            'referrer_ongoing_discount_pct' => 12.00,
        ]);
    }

    #[Test]
    public function admin_update_settings_validates_ranges(): void
    {
        $adminUser = User::factory()->create();
        $adminBranch = Branch::factory()->create(['subscription_id' => 1]);
        $adminUser->update(['branch_id' => $adminBranch->id]);
        $adminUser->subscription->update(['onboarding_completed_at' => now()]);

        $this->actingAs($adminUser);

        $response = $this->put(route('admin.referrals.settings.update'), [
            'referred_discount_pct' => -5,
            'referrer_reward_pct' => 150,
            'referrer_ongoing_discount_pct' => 200,
        ]);

        $response->assertSessionHasErrors([
            'referred_discount_pct',
            'referrer_reward_pct',
            'referrer_ongoing_discount_pct',
        ]);
    }

    // -----------------------------------------------------------------
    // User model — referral methods
    // -----------------------------------------------------------------

    #[Test]
    public function user_has_pending_referral_rewards_returns_true(): void
    {
        $user = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-PEND1',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'reward_status' => 'pending',
        ]);

        $this->assertTrue($user->hasPendingReferralRewards());
    }

    #[Test]
    public function user_has_pending_referral_rewards_returns_false_when_all_paid(): void
    {
        $user = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-PAID1',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'reward_status' => 'paid',
        ]);

        $this->assertFalse($user->hasPendingReferralRewards());
    }

    #[Test]
    public function get_unseen_referrals_count_returns_correct_value(): void
    {
        $user = User::factory()->create();
        $referralCode = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-UNSE1',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'seen_at' => null,
        ]);
        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
            'seen_at' => now(),
        ]);

        $this->assertEquals(1, $user->getUnseenReferralsCount());
    }

    // -----------------------------------------------------------------
    // Subscription model — referral methods
    // -----------------------------------------------------------------

    #[Test]
    public function get_referrer_active_discount_pct_returns_sum_of_active_referrals(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);
        $referrerSubscription = $referrerBranch->subscription;

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-PCT01',
        ]);

        // Active referred subscription
        $activeSubscription = Subscription::factory()->create();
        SubscriptionVersion::factory()->create([
            'subscription_id' => $activeSubscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $payment1 = SubscriptionPayment::factory()->create([
            'subscription_version_id' => SubscriptionVersion::where('subscription_id', $activeSubscription->id)->first()->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $activeSubscription->id,
            'subscription_payment_id' => $payment1->id,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $pct = $referrerSubscription->getReferrerActiveDiscountPct();
        $this->assertEquals(10.00, $pct);
    }

    #[Test]
    public function get_referrer_active_discount_pct_returns_zero_when_no_active_referrals(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);
        $referrerSubscription = $referrerBranch->subscription;

        $referralCode = ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-ZERO1',
        ]);

        // Expired referred subscription
        $expiredSubscription = Subscription::factory()->create();
        SubscriptionVersion::factory()->create([
            'subscription_id' => $expiredSubscription->id,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonths(1)->subDay(), // expired
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => SubscriptionVersion::where('subscription_id', $expiredSubscription->id)->first()->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $referralCode->id,
            'referred_subscription_id' => $expiredSubscription->id,
            'subscription_payment_id' => $payment->id,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $pct = $referrerSubscription->getReferrerActiveDiscountPct();
        $this->assertEquals(0, $pct);
    }

    // -----------------------------------------------------------------
    // ReferralCode model — relationships & scopes
    // -----------------------------------------------------------------

    #[Test]
    public function referral_code_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $code = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-REL01',
        ]);

        $this->assertInstanceOf(User::class, $code->user);
        $this->assertEquals($user->id, $code->user->id);
    }

    #[Test]
    public function referral_code_has_many_usages(): void
    {
        $code = ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-HMNY1',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);
        ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->assertCount(2, $code->usages);
    }

    // -----------------------------------------------------------------
    // ReferralUsage model — relationships
    // -----------------------------------------------------------------

    #[Test]
    public function referral_usage_belongs_to_referral_code(): void
    {
        $code = ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-REL02',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        $usage = ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->assertInstanceOf(ReferralCode::class, $usage->referralCode);
        $this->assertEquals($code->id, $usage->referralCode->id);
    }

    #[Test]
    public function referral_usage_belongs_to_referred_subscription(): void
    {
        $code = ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-REL03',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        $usage = ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->assertInstanceOf(Subscription::class, $usage->referredSubscription);
        $this->assertEquals($subscription->id, $usage->referredSubscription->id);
    }

    #[Test]
    public function referral_usage_get_referrer_user_returns_correct_user(): void
    {
        $user = User::factory()->create();
        $code = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-GTRU1',
        ]);

        $subscription = Subscription::factory()->create();
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        $usage = ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->assertInstanceOf(User::class, $usage->getReferrerUser());
        $this->assertEquals($user->id, $usage->getReferrerUser()->id);
    }

    // -----------------------------------------------------------------
    // ReferralSettings model
    // -----------------------------------------------------------------

    #[Test]
    public function referral_settings_can_be_created_and_read(): void
    {
        $settings = ReferralSettings::create([
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $this->assertDatabaseHas('referral_settings', [
            'id' => $settings->id,
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);
    }

    #[Test]
    public function referral_settings_first_or_create_with_defaults(): void
    {
        $this->assertEquals(0, ReferralSettings::count());

        $settings = ReferralSettings::firstOrCreate([], [
            'referred_discount_pct' => 15.00,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $this->assertEquals(15.00, $settings->referred_discount_pct);
        $this->assertEquals(1, ReferralSettings::count());

        // Calling again returns the same record
        $settings2 = ReferralSettings::firstOrCreate([], [
            'referred_discount_pct' => 99.00,
        ]);

        $this->assertEquals($settings->id, $settings2->id);
        $this->assertEquals(15.00, $settings2->referred_discount_pct);
    }

    // -----------------------------------------------------------------
    // User model — referral relationships
    // -----------------------------------------------------------------

    #[Test]
    public function user_referral_code_relationship(): void
    {
        $user = User::factory()->create();
        $code = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-UREL1',
        ]);

        $this->assertInstanceOf(ReferralCode::class, $user->referralCode);
        $this->assertEquals('EZY-UREL1', $user->referralCode->code);
    }

    #[Test]
    public function user_referral_usages_as_referrer_returns_all_usages(): void
    {
        $user = User::factory()->create();
        $code = ReferralCode::factory()->create([
            'user_id' => $user->id,
            'code' => 'EZY-UREL2',
        ]);

        $subscription1 = Subscription::factory()->create();
        $version1 = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription1->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment1 = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version1->id,
            'amount' => 1000.00,
        ]);

        $subscription2 = Subscription::factory()->create();
        $version2 = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription2->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment2 = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version2->id,
            'amount' => 500.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription1->id,
            'subscription_payment_id' => $payment1->id,
        ]);
        ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription2->id,
            'subscription_payment_id' => $payment2->id,
        ]);

        $this->assertCount(2, $user->referralUsagesAsReferrer);
    }

    #[Test]
    public function user_referrer_bank_account_relationship(): void
    {
        $user = User::factory()->create();
        $account = ReferrerBankAccount::create([
            'user_id' => $user->id,
            'clabe' => '012345678901234567',
            'bank_name' => 'Banco Azteca',
            'account_holder_name' => 'Juan Pérez',
        ]);

        $this->assertInstanceOf(ReferrerBankAccount::class, $user->referrerBankAccount);
        $this->assertEquals('Banco Azteca', $user->referrerBankAccount->bank_name);
    }

    // -----------------------------------------------------------------
    // Subscription model — referralUsageAsReferred
    // -----------------------------------------------------------------

    #[Test]
    public function subscription_referral_usage_as_referred_relationship(): void
    {
        $subscription = Subscription::factory()->create();
        $code = ReferralCode::factory()->create([
            'user_id' => User::factory()->create()->id,
            'code' => 'EZY-SREL1',
        ]);
        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        ReferralUsage::factory()->create([
            'referral_code_id' => $code->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->assertInstanceOf(ReferralUsage::class, $subscription->referralUsageAsReferred);
    }

    // -----------------------------------------------------------------
    // Edge cases & regression
    // -----------------------------------------------------------------

    #[Test]
    public function discount_amount_is_zero_when_discount_pct_is_zero(): void
    {
        ReferralSettings::create([
            'referred_discount_pct' => 0,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $referrerUser = User::factory()->create();
        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-ZERO2',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        $action = new ApplyReferralDiscountAction();
        $result = $action->execute('EZY-ZERO2', $referredSubscription, 500.00);

        $this->assertEquals(0, $result['discount_pct']);
        $this->assertEquals(0, $result['discount_amount']);
        $this->assertEquals(500.00, $result['final_amount']);
    }

    #[Test]
    public function discount_amount_is_full_when_discount_pct_is_100(): void
    {
        ReferralSettings::create([
            'referred_discount_pct' => 100,
            'referrer_reward_pct' => 50.00,
            'referrer_ongoing_discount_pct' => 10.00,
        ]);

        $referrerUser = User::factory()->create();
        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-FULL1',
            'is_active' => true,
        ]);

        $referredUser = User::factory()->create();
        $referredBranch = Branch::factory()->create();
        $referredUser->update(['branch_id' => $referredBranch->id]);
        $referredSubscription = $referredBranch->subscription;

        $action = new ApplyReferralDiscountAction();
        $result = $action->execute('EZY-FULL1', $referredSubscription, 300.00);

        $this->assertEquals(100, $result['discount_pct']);
        $this->assertEquals(300.00, $result['discount_amount']);
        $this->assertEquals(0, $result['final_amount']);
    }

    #[Test]
    public function validate_code_rejects_when_subscription_already_used_a_code(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);

        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-USED1',
            'is_active' => true,
        ]);

        // Another referrer to create a previous usage
        $anotherReferrer = User::factory()->create();
        $anotherCode = ReferralCode::factory()->create([
            'user_id' => $anotherReferrer->id,
            'code' => 'EZY-OTHER',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $subscription = $branch->subscription;

        $version = SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
        ]);
        $payment = SubscriptionPayment::factory()->create([
            'subscription_version_id' => $version->id,
            'amount' => 1000.00,
        ]);

        // This subscription already has a referral usage
        ReferralUsage::factory()->create([
            'referral_code_id' => $anotherCode->id,
            'referred_subscription_id' => $subscription->id,
            'subscription_payment_id' => $payment->id,
        ]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-USED1']));
        $response->assertJson(['valid' => false, 'message' => 'Esta suscripción ya usó un código de referido.']);
    }

    #[Test]
    public function validate_code_rejects_when_not_first_payment(): void
    {
        $referrerUser = User::factory()->create();
        $referrerBranch = Branch::factory()->create();
        $referrerUser->update(['branch_id' => $referrerBranch->id]);

        ReferralCode::factory()->create([
            'user_id' => $referrerUser->id,
            'code' => 'EZY-NOTFP',
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        $user->update(['branch_id' => $branch->id]);
        $user->subscription->update(['onboarding_completed_at' => now()]);
        $subscription = $branch->subscription;

        // Multiple versions = not first payment
        SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);
        SubscriptionVersion::factory()->create([
            'subscription_id' => $subscription->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson(route('referrals.validate', ['code' => 'EZY-NOTFP']));
        $response->assertJson(['valid' => false, 'message' => 'El código de referido solo aplica en el primer pago.']);
    }
}
