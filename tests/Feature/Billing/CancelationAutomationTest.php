<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\RefreshCancelationStatusAction;
use App\Enums\InvoiceStatus;
use App\Enums\PacAccountStatus;
use App\Jobs\Billing\RefreshPendingCancelationsJob;
use App\Mail\CancelationResolvedNotification;
use App\Models\Branch;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\PacAccount;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 3 — T301/T302: the hourly job re-checks cancelations awaiting the
 * receiver's acceptance against the SAT and notifies the subscriber when
 * they are resolved (accepted / rejected / expired).
 */
class CancelationAutomationTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '11111111-2222-3333-4444-555555555555';
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

    private function makePendingCancelationInvoice(Branch $branch, FiscalProfile $profile, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'branch_id'                       => $branch->id,
            'fiscal_profile_id'               => $profile->id,
            'series'                          => 'T',
            'folio'                           => '200',
            'status'                          => InvoiceStatus::CANCELATION_PENDING,
            'tipo_comprobante'                => 'I',
            'receiver_rfc'                    => 'XAXX010101000',
            'receiver_legal_name'             => 'Cliente prueba',
            'receiver_postal_code'            => '45000',
            'receiver_tax_regime'             => '616',
            'cfdi_use'                        => 'S01',
            'currency'                        => 'MXN',
            'total'                           => 100,
            'uuid'                            => self::UUID,
            'sello_cfdi'                      => 'SELLO1234',
            'cancelation_requires_acceptance' => true,
            'cancelation_requested_at'        => now()->subHours(10),
            'cancelation_last_checked_at'     => null,
        ], $overrides));
    }

    /**
     * Minimal SAT SOAP response with the fields the parser reads.
     */
    private function satResponse(string $estado, ?string $estatusCancelacion = null): string
    {
        $estatus = $estatusCancelacion !== null
            ? '<a:EstatusCancelacion>' . $estatusCancelacion . '</a:EstatusCancelacion>'
            : '';

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . '<ConsultaResponse xmlns="http://tempuri.org/">'
            . '<ConsultaResult xmlns:a="http://schemas.datacontract.org/2004/07/Sat.ConsultaCFDIService">'
            . '<a:CodigoEstatus>S - 100</a:CodigoEstatus>'
            . '<a:EsCancelable>Cancelable con aceptación</a:EsCancelable>'
            . '<a:Estado>' . $estado . '</a:Estado>'
            . $estatus
            . '</ConsultaResult></ConsultaResponse></s:Body></s:Envelope>';
    }

    private function runJob(): void
    {
        (new RefreshPendingCancelationsJob)->handle(app(RefreshCancelationStatusAction::class));
    }

    // ─────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function it_marks_a_cancelation_accepted_by_the_receiver_as_canceled(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';

        [, $branch, $profile] = $this->makeEnvironment();
        $invoice = $this->makePendingCancelationInvoice($branch, $profile);

        Http::fake([
            '*ConsultaCFDIService.svc' => Http::response($this->satResponse('Cancelado', 'Cancelación aceptada')),
        ]);

        $this->runJob();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::CANCELED, $invoice->status);
        $this->assertNotNull($invoice->canceled_at);

        Mail::assertSent(CancelationResolvedNotification::class, function ($mail) use ($invoice) {
            return $mail->hasTo(self::SUBSCRIBER_EMAIL)
                && $mail->result === 'canceled'
                && $mail->invoice->id === $invoice->id;
        });

        // T306 — every SAT consultation leaves an audit row in pac_call_logs.
        $this->assertDatabaseHas('pac_call_logs', [
            'operation'         => 'cancel_status',
            'fiscal_profile_id' => $profile->id,
        ]);
    }

    #[Test]
    public function it_reverts_the_invoice_to_certified_when_the_receiver_rejects(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';

        [, $branch, $profile] = $this->makeEnvironment();
        $invoice = $this->makePendingCancelationInvoice($branch, $profile);

        Http::fake([
            '*ConsultaCFDIService.svc' => Http::response($this->satResponse('Vigente', 'Solicitud rechazada')),
        ]);

        $this->runJob();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::CERTIFIED, $invoice->status);
        $this->assertFalse($invoice->cancelation_requires_acceptance);

        Mail::assertSent(CancelationResolvedNotification::class, fn ($mail) => $mail->result === 'rejected');
    }

    #[Test]
    public function it_notifies_once_when_the_acceptance_deadline_expires(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';

        [, $branch, $profile] = $this->makeEnvironment();
        $invoice = $this->makePendingCancelationInvoice($branch, $profile);

        Http::fake([
            '*ConsultaCFDIService.svc' => Http::response($this->satResponse('Vigente', 'Plazo vencido')),
        ]);

        $this->runJob();

        // The invoice stays pending — the user must re-request the cancelation.
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::CANCELATION_PENDING, $invoice->status);
        $this->assertSame('Plazo vencido', $invoice->cancelation_status);

        Mail::assertSentTimes(CancelationResolvedNotification::class, 1);
        Mail::assertSent(CancelationResolvedNotification::class, fn ($mail) => $mail->result === 'expired');

        // A second run skips the invoice (deadline already expired) — no extra
        // SAT call and no repeated email.
        $this->runJob();

        Http::assertSentCount(1);
        Mail::assertSentTimes(CancelationResolvedNotification::class, 1);
    }

    #[Test]
    public function it_ignores_cancelations_whose_deadline_already_expired(): void
    {
        $this->app['env'] = 'production';

        [, $branch, $profile] = $this->makeEnvironment();
        $this->makePendingCancelationInvoice($branch, $profile, ['cancelation_status' => 'Plazo vencido']);

        Http::fake();

        $this->runJob();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_resolution_email_renders_every_outcome(): void
    {
        [, $branch, $profile] = $this->makeEnvironment();
        $invoice = $this->makePendingCancelationInvoice($branch, $profile);

        $outcomes = [
            'canceled' => 'Cancelación aceptada',
            'rejected' => 'Cancelación rechazada',
            'expired'  => 'Solicitud de cancelación vencida',
        ];

        foreach ($outcomes as $result => $expectedHeading) {
            $html = (new CancelationResolvedNotification($invoice, $result))->render();

            $this->assertStringContainsString($expectedHeading, $html);
        }
    }
}
