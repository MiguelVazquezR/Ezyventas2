<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ConfirmManualReviewAction;
use App\Actions\Billing\ReleaseManualReviewAction;
use App\Actions\Billing\StampInvoiceAction;
use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Exceptions\Billing\InsufficientStampsException;
use App\Exceptions\Billing\PacValidationException;
use App\Jobs\Billing\ResolveAmbiguousStampJob;
use App\Models\Branch;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\PacAccount;
use App\Models\Billing\StampMovement;
use App\Models\Billing\StampReservation;
use App\Models\Subscription;
use App\Models\SubscriptionVersion;
use App\Services\Billing\SWSapienService;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StampReservationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();

        // Evita escribir XML reales de prueba en disco.
        Storage::fake('public');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function makeEnvironment(string $type = 'subaccount'): array
    {
        $subscription = Subscription::factory()->create();

        // La facturación se activa automáticamente con el módulo module_billing.
        $version = SubscriptionVersion::create([
            'subscription_id' => $subscription->id,
            'start_date'      => now(),
            'end_date'        => now()->addDays(30),
        ]);
        $version->items()->create([
            'item_key'       => 'module_billing',
            'item_type'      => 'module',
            'name'           => 'Facturación',
            'quantity'       => 1,
            'unit_price'     => 15,
            'billing_period' => BillingPeriod::MONTHLY,
        ]);

        $branch = Branch::factory()->create(['subscription_id' => $subscription->id]);

        if ($type === 'subaccount') {
            $account = PacAccount::factory()->create([
                'subscription_id' => $subscription->id,
                'account_type'    => PacAccountType::SUBACCOUNT,
                'status'          => PacAccountStatus::ACTIVE,
                'sw_user_id'      => 'SW123',
            ]);
        } else {
            $account = PacAccount::factory()->shared()->create([
                'subscription_id' => $subscription->id,
                'status'          => PacAccountStatus::ACTIVE,
            ]);
        }

        $profile = FiscalProfile::factory()->create([
            'subscription_id'    => $subscription->id,
            'pac_account_id'     => $account->id,
            'manifest_signed_at' => now(),
        ]);

        return [$subscription, $branch, $account, $profile];
    }

    private function makeDraftInvoice(Branch $branch, FiscalProfile $profile): Invoice
    {
        return Invoice::create([
            'branch_id'             => $branch->id,
            'fiscal_profile_id'     => $profile->id,
            'series'                => 'T',
            'folio'                 => '100',
            'status'                => InvoiceStatus::DRAFT,
            'tipo_comprobante'      => 'I',
            'receiver_rfc'          => 'XAXX010101000',
            'receiver_legal_name'   => 'Cliente prueba',
            'receiver_postal_code'  => '45000',
            'receiver_tax_regime'   => '616',
            'cfdi_use'              => 'S01',
            'currency'              => 'MXN',
            'total'                 => 100,
        ]);
    }

    private function successResponse(): array
    {
        return [
            'status' => 'success',
            'data'   => [
                'uuid'               => self::UUID,
                'cfdi'               => '<cfdi>xml</cfdi>',
                'pdf'                => 'https://example.test/pdf.pdf',
                'fechaTimbrado'      => '2026-08-13T10:00:00',
                'selloCFDI'          => 'SELLOCFDI',
                'selloSAT'           => 'SELLOSAT',
                'noCertificadoSAT'   => '00001000000000000000',
                'cadenaOriginalSAT'  => '||1.1|' . self::UUID . '|2026-08-13T10:00:00|AAA010101AAA|SELLOSAT|00001000000000000000||',
                'qrCode'             => 'base64qr',
            ],
        ];
    }

    private function duplicate307Response(): array
    {
        return [
            'status' => 'error',
            'data'   => [
                'code'              => '307',
                'message'           => 'El comprobante contiene un timbre previo',
                'uuid'              => self::UUID,
                'cfdi'              => '<cfdi>original</cfdi>',
                'cadenaOriginalSAT' => '||1.1|' . self::UUID . '|2026-08-13T10:00:00|AAA010101AAA|SELLOSAT|00001000000000000000||',
            ],
        ];
    }

    private function fakeAuthAndBalance(): void
    {
        Http::fake([
            '*/v2/security/authenticate' => Http::response(['data' => ['token' => 'test-token'], 'status' => 'success']),
            '*/management/v2/api/dealers/balance/users/*' => Http::response(['data' => ['stampsBalance' => 100, 'stampsAssigned' => 200, 'stampsUsed' => 100]]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Success paths
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function subaccount_success_creates_confirmed_reservation_and_certifies(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);

        $this->fakeAuthAndBalance();
        Http::fake(['*/v3/cfdi33/issue/json/v4' => Http::response($this->successResponse())]);

        app(StampInvoiceAction::class)->execute($invoice);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertEquals(self::UUID, $invoice->uuid);

        $reservation = StampReservation::where('reference_id', $invoice->id)->first();
        $this->assertNotNull($reservation);
        $this->assertEquals('confirmed', $reservation->status);
        $this->assertNotNull($reservation->confirmed_at);
        $this->assertNotEmpty($reservation->customid);

        // El StampMovementObserver registra el 'exit' automáticamente.
        $this->assertDatabaseHas('stamp_movements', [
            'fiscal_profile_id' => $profile->id,
            'type'              => 'exit',
            'reference_type'    => Invoice::class,
            'reference_id'      => $invoice->id,
        ]);
    }

    #[Test]
    public function normal_account_with_balance_certifies(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('shared');
        StampMovement::create([
            'fiscal_profile_id' => $profile->id,
            'type'              => 'entry',
            'description'       => 'Test',
            'quantity'          => 5,
            'balance_after'     => 5,
        ]);
        $invoice = $this->makeDraftInvoice($branch, $profile);

        $this->fakeAuthAndBalance();
        Http::fake(['*/v3/cfdi33/issue/json/v4' => Http::response($this->successResponse())]);

        app(StampInvoiceAction::class)->execute($invoice);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertEquals('confirmed', StampReservation::where('reference_id', $invoice->id)->first()->status);
    }

    // ─────────────────────────────────────────────────────────────
    // Insufficient stamps
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function normal_account_without_balance_throws_and_creates_no_reservation(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('shared');
        $invoice = $this->makeDraftInvoice($branch, $profile);

        try {
            app(StampInvoiceAction::class)->execute($invoice);
            $this->fail('Expected InsufficientStampsException');
        } catch (InsufficientStampsException $e) {
            $this->assertStringContainsString('timbres suficientes', $e->getMessage());
        }

        // No se creó reserva ni se tocó el folio (la factura conserva su folio).
        $this->assertDatabaseCount('stamp_reservations', 0);
    }

    // ─────────────────────────────────────────────────────────────
    // Validation error (clear, not ambiguous)
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function validation_error_releases_reservation_and_returns_to_draft(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);

        $this->fakeAuthAndBalance();
        Http::fake([
            '*/v3/cfdi33/issue/json/v4' => Http::response(['status' => 'error', 'message' => 'CFDI40119 concepto invalido'], 400),
        ]);

        try {
            app(StampInvoiceAction::class)->execute($invoice);
            $this->fail('Expected PacValidationException');
        } catch (PacValidationException $e) {
            $this->assertStringContainsString('CFDI40119', $e->getMessage());
        }

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);

        $reservation = StampReservation::where('reference_id', $invoice->id)->first();
        $this->assertEquals('released', $reservation->status);
        $this->assertNotNull($reservation->released_at);
    }

    // ─────────────────────────────────────────────────────────────
    // Duplicate 307 recovery
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function duplicate_307_recovers_and_confirms_without_extra_stamp(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);

        $this->fakeAuthAndBalance();
        Http::fake(['*/v3/cfdi33/issue/json/v4' => Http::response($this->duplicate307Response(), 400)]);

        app(StampInvoiceAction::class)->execute($invoice);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertEquals(self::UUID, $invoice->uuid);
        $this->assertEquals('AAA010101AAA', $invoice->rfc_prov_certif);

        $reservation = StampReservation::where('reference_id', $invoice->id)->first();
        $this->assertEquals('confirmed', $reservation->status);

        // Solo un 'exit' (un timbre), no dos.
        $this->assertEquals(1, StampMovement::where('fiscal_profile_id', $profile->id)->where('type', 'exit')->count());
    }

    // ─────────────────────────────────────────────────────────────
    // Timeout → ambiguous → job
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function timeout_becomes_ambiguous_and_dispatches_resolve_job(): void
    {
        Queue::fake();

        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);

        $this->fakeAuthAndBalance();
        Http::fake([
            '*/v3/cfdi33/issue/json/v4' => fn ($request) => throw new ConnectionException('timed out'),
        ]);

        app(StampInvoiceAction::class)->execute($invoice);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::AWAITING_VERIFICATION, $invoice->status);

        $reservation = StampReservation::where('reference_id', $invoice->id)->first();
        $this->assertEquals('ambiguous', $reservation->status);

        Queue::assertPushed(ResolveAmbiguousStampJob::class);
    }

    #[Test]
    public function resolve_job_recovers_previous_stamp_via_307(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);
        $invoice->update(['status' => InvoiceStatus::AWAITING_VERIFICATION]);

        $reservation = StampReservation::create([
            'fiscal_profile_id' => $profile->id,
            'reference_type'    => Invoice::class,
            'reference_id'      => $invoice->id,
            'customid'          => (string) Str::uuid(),
            'quantity'          => 1,
            'status'            => 'ambiguous',
            'attempts'          => 1,
        ]);

        $this->fakeAuthAndBalance();
        Http::fake(['*/v3/cfdi33/issue/json/v4' => Http::response($this->duplicate307Response(), 400)]);

        (new ResolveAmbiguousStampJob($reservation->id))->handle(app(SWSapienService::class));

        $reservation->refresh();
        $this->assertEquals('confirmed', $reservation->status);

        $invoice->refresh();
        $this->assertEquals(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertEquals(self::UUID, $invoice->uuid);
        $this->assertEquals(1, StampMovement::where('fiscal_profile_id', $profile->id)->where('type', 'exit')->count());
    }

    // ─────────────────────────────────────────────────────────────
    // Manual review panel actions
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function confirm_manual_review_marks_confirmed_and_certifies(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('shared');
        $invoice = $this->makeDraftInvoice($branch, $profile);
        $invoice->update(['status' => InvoiceStatus::AWAITING_VERIFICATION, 'requires_manual_review' => true]);

        $reservation = StampReservation::create([
            'fiscal_profile_id' => $profile->id,
            'reference_type'    => Invoice::class,
            'reference_id'      => $invoice->id,
            'customid'          => (string) Str::uuid(),
            'quantity'          => 1,
            'status'            => 'manual_review',
            'attempts'          => 5,
            'last_pac_response' => ['error' => 'timeout'],
        ]);

        app(ConfirmManualReviewAction::class)->execute($reservation, '22222222-3333-4444-5555-666666666666', null);

        $reservation->refresh();
        $invoice->refresh();

        $this->assertEquals('confirmed', $reservation->status);
        $this->assertEquals(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertFalse($invoice->requires_manual_review);
        $this->assertEquals('22222222-3333-4444-5555-666666666666', $invoice->uuid);
    }

    #[Test]
    public function release_manual_review_returns_invoice_to_draft(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('shared');
        $invoice = $this->makeDraftInvoice($branch, $profile);
        $invoice->update(['status' => InvoiceStatus::AWAITING_VERIFICATION, 'requires_manual_review' => true]);

        $reservation = StampReservation::create([
            'fiscal_profile_id' => $profile->id,
            'reference_type'    => Invoice::class,
            'reference_id'      => $invoice->id,
            'customid'          => (string) Str::uuid(),
            'quantity'          => 1,
            'status'            => 'manual_review',
            'attempts'          => 5,
        ]);

        app(ReleaseManualReviewAction::class)->execute($reservation);

        $reservation->refresh();
        $invoice->refresh();

        $this->assertEquals('released', $reservation->status);
        $this->assertNotNull($reservation->released_at);
        $this->assertEquals(InvoiceStatus::DRAFT, $invoice->status);
        $this->assertFalse($invoice->requires_manual_review);
    }

    // ─────────────────────────────────────────────────────────────
    // WalletService
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function wallet_available_balance_is_net_movements_minus_held_and_ambiguous(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('shared');

        StampMovement::create(['fiscal_profile_id' => $profile->id, 'type' => 'entry', 'description' => 'a', 'quantity' => 10, 'balance_after' => 10]);
        StampMovement::create(['fiscal_profile_id' => $profile->id, 'type' => 'exit', 'description' => 'b', 'quantity' => 1, 'balance_after' => 9]);

        StampReservation::create(['fiscal_profile_id' => $profile->id, 'customid' => (string) Str::uuid(), 'quantity' => 3, 'status' => 'held']);
        StampReservation::create(['fiscal_profile_id' => $profile->id, 'customid' => (string) Str::uuid(), 'quantity' => 2, 'status' => 'ambiguous']);
        StampReservation::create(['fiscal_profile_id' => $profile->id, 'customid' => (string) Str::uuid(), 'quantity' => 1, 'status' => 'confirmed']);

        // 10 - 1 - 3 - 2 = 4 (confirmed no resta).
        $this->assertEquals(4, app(WalletService::class)->availableBalance($profile->id));
    }

    // ─────────────────────────────────────────────────────────────
    // Folio counter
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function reserve_next_folio_seeds_from_existing_max_and_increments(): void
    {
        [$subscription, $branch, $account, $profile] = $this->makeEnvironment('subaccount');
        $invoice = $this->makeDraftInvoice($branch, $profile);
        $invoice->update(['folio' => '7']); // folio existente

        $service = app(SWSapienService::class);

        $this->assertEquals(8, $service->reserveNextFolio($branch->id, 'T'));
        $this->assertEquals(9, $service->reserveNextFolio($branch->id, 'T'));

        // Series distintas tienen contadores independientes.
        $this->assertEquals(1, $service->reserveNextFolio($branch->id, null));
    }
}
