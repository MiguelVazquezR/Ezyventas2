<?php

namespace Tests\Feature\Billing;

use App\Enums\PacAccountStatus;
use App\Jobs\Billing\ReconcileSharedAccountBalancesJob;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\PacAccount;
use App\Models\Billing\PacCallLog;
use App\Models\Billing\StampMovement;
use App\Services\Billing\StampMovementService;
use App\Services\Billing\WalletService;
use App\Services\SW\SWUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WelcomeStampsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSharedActiveAccount(): PacAccount
    {
        return PacAccount::factory()->shared()->create([
            'status' => PacAccountStatus::ACTIVE,
        ]);
    }

    private function makeProfile(PacAccount $account): FiscalProfile
    {
        return FiscalProfile::factory()->create([
            'subscription_id' => $account->subscription_id,
            'pac_account_id'  => $account->id,
        ]);
    }

    #[Test]
    public function grant_welcome_stamps_creates_an_entry_of_five(): void
    {
        $account = $this->makeSharedActiveAccount();
        $profile = $this->makeProfile($account);

        $movement = app(StampMovementService::class)->grantWelcomeStamps($profile);

        $this->assertNotNull($movement);
        $this->assertSame('entry', $movement->type);
        $this->assertSame(5, $movement->quantity);
        $this->assertSame(5, $movement->balance_after);
        $this->assertSame('gift', $movement->metadata['source'] ?? null);
        $this->assertSame('Timbres de regalo de bienvenida', $movement->description);

        // La wallet local incluye el regalo → habilita timbrar.
        $this->assertSame(5, app(WalletService::class)->availableBalance($profile->id));
        $this->assertSame(5, app(WalletService::class)->welcomeStampsGranted($profile->id));
    }

    #[Test]
    public function grant_welcome_stamps_is_idempotent_per_profile(): void
    {
        $account = $this->makeSharedActiveAccount();
        $profile = $this->makeProfile($account);
        $service = app(StampMovementService::class);

        $first  = $service->grantWelcomeStamps($profile);
        $second = $service->grantWelcomeStamps($profile);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, StampMovement::where('fiscal_profile_id', $profile->id)->count());
        $this->assertSame(5, app(WalletService::class)->availableBalance($profile->id));
        $this->assertSame(5, app(WalletService::class)->welcomeStampsGranted($profile->id));
    }

    #[Test]
    public function welcome_stamps_are_excluded_from_reconciliation_expected(): void
    {
        $account = $this->makeSharedActiveAccount();
        $profile = $this->makeProfile($account);
        app(StampMovementService::class)->grantWelcomeStamps($profile);

        // PAC: saldo real 0 (los 5 son solo de la wallet local).
        Http::fake([
            '*/v2/security/authenticate'        => Http::response(['data' => ['token' => 'tok']]),
            '*/management/v2/api/users/balance' => Http::response(['data' => ['stampsBalance' => 0]]),
        ]);

        (new ReconcileSharedAccountBalancesJob())->handle(
            app(SWUserService::class),
            app(WalletService::class),
        );

        // Sin regalo, el esperado sería 5 → mismatch. Con la exclusión debe ser 0.
        $log = PacCallLog::where('operation', 'reconcile')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(0, $log->request_payload['expected_balance'] ?? -1);
        $this->assertSame(0, $log->response_body['difference'] ?? -1);
    }

    #[Test]
    public function gift_is_consumed_before_buying_more(): void
    {
        $account = $this->makeSharedActiveAccount();
        $profile = $this->makeProfile($account);
        $service = app(StampMovementService::class);
        $service->grantWelcomeStamps($profile);

        // Consume 3 timbres (exits) → quedan 2 de regalo.
        StampMovement::create([
            'fiscal_profile_id' => $profile->id,
            'type'              => 'exit',
            'description'       => 'Timbrado de factura de prueba 1',
            'quantity'          => 1,
            'balance_after'     => 4,
        ]);
        StampMovement::create([
            'fiscal_profile_id' => $profile->id,
            'type'              => 'exit',
            'description'       => 'Timbrado de factura de prueba 2',
            'quantity'          => 1,
            'balance_after'     => 3,
        ]);
        StampMovement::create([
            'fiscal_profile_id' => $profile->id,
            'type'              => 'exit',
            'description'       => 'Timbrado de factura de prueba 3',
            'quantity'          => 1,
            'balance_after'     => 2,
        ]);

        $wallet = app(WalletService::class)->availableBalance($profile->id);
        $this->assertSame(2, $wallet);

        // Reconciliación: wallet 2 − regalo 5 → real esperado 0 (aún sin compras).
        $this->assertSame(0, max($wallet - app(WalletService::class)->welcomeStampsGranted($profile->id), 0));
    }
}
