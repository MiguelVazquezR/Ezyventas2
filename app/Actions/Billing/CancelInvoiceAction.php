<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Billing\Invoice;
use App\Models\Transaction;
use App\Services\Billing\SatConsultationService;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CancelInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
        private readonly SatConsultationService $satConsultationService,
    ) {}

    /**
     * Cancel a CFDI invoice via SW Sapien.
     *
     * The SAT decides whether the cancelation applies immediately
     * ("Cancelable sin aceptación") or requires the receiver's acceptance
     * ("Cancelable con aceptación"). SW's cancelation payload does not state
     * which case applies, so the SAT is consulted before sending the request.
     * When it cannot be determined upfront, CFDIs stamped within the last
     * 72 hours are treated as immediate and older ones are re-checked
     * against the SAT after the request.
     *
     * Returns a status string:
     *  - 'canceled'           → immediate cancelation (no acceptance needed)
     *  - 'pending_acceptance' → receiver must accept
     *
     * @return array{status: string, message: string}
     */
    public function execute(Invoice $invoice, string $cancellationReason, ?string $substitutionUuid = null): array
    {
        if (! $invoice->isCertified()) {
            abort(422, 'Solo las facturas certificadas pueden ser canceladas.');
        }

        $invoice->load('fiscalProfile');

        if (! $invoice->fiscalProfile?->rfc) {
            abort(422, 'No se encontró el perfil fiscal asociado a esta factura.');
        }

        $requiresAcceptance = $this->requiresAcceptance($invoice);

        $responseData = $this->swService->cancel(
            $invoice,
            $invoice->fiscalProfile->rfc,
            $cancellationReason,
            $substitutionUuid,
        );

        $folioCode = $this->extractFolioCode($responseData);

        // 201: cancelation request accepted (acceptance may still be pending);
        // 202: request was already sent before. Any other code means the SAT
        // rejected the request.
        if ($folioCode !== null && ! in_array($folioCode, ['201', '202'], true)) {
            Log::warning('CFDI cancelation rejected by SAT', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
                'folio_code' => $folioCode,
            ]);

            throw new \RuntimeException($this->folioErrorMessage($responseData, $folioCode));
        }

        // When the SAT could not be consulted upfront, fall back to two rules:
        // cancelations requested within the first 72 hours after stamping do
        // not require the receiver's acceptance, and for older CFDIs the
        // cancelation may already be effective at the SAT.
        if ($requiresAcceptance === null) {
            $requiresAcceptance = ! $this->isWithin72Hours($invoice) && ! $this->isCanceledAtSat($invoice);
        }

        if ($requiresAcceptance === true) {
            // The receiver must approve the request before the SAT cancels the CFDI.
            $invoice->update([
                'status'                          => InvoiceStatus::CANCELATION_PENDING,
                'cancellation_reason'             => $cancellationReason,
                'cancelation_requires_acceptance' => true,
                'cancelation_requested_at'        => now(),
            ]);

            Log::info('CFDI cancelation pending receiver acceptance', [
                'invoice_id' => $invoice->id,
                'uuid'       => $invoice->uuid,
                'folio_code' => $folioCode,
            ]);

            return [
                'status'  => 'pending_acceptance',
                'message' => 'Se envió la solicitud de cancelación. Tu cliente (RFC receptor) debe aceptarla o rechazarla ante el SAT. Te avisaremos cuando se resuelva. Mientras tanto, esta factura sigue vigente para efectos fiscales.',
            ];
        }

        // Immediate cancelation — the CFDI is now canceled at the SAT.
        $invoice->update([
            'status'              => InvoiceStatus::CANCELED,
            'cancellation_reason' => $cancellationReason,
            'canceled_at'         => now(),
        ]);

        // Release the linked POS sale so it can be invoiced again.
        if ($invoice->transaction_id) {
            Transaction::where('id', $invoice->transaction_id)->update(['invoiced' => false]);
        }

        Log::info('CFDI canceled immediately', [
            'invoice_id' => $invoice->id,
            'uuid'       => $invoice->uuid,
            'folio_code' => $folioCode,
        ]);

        return [
            'status'  => 'canceled',
            'message' => 'Factura cancelada correctamente.',
        ];
    }

    /**
     * Ask the SAT whether this CFDI requires the receiver's acceptance before
     * it can be canceled. Returns null when it cannot be determined (SAT
     * unreachable, CFDI not listed yet, or an unrecognized value).
     */
    private function requiresAcceptance(Invoice $invoice): ?bool
    {
        try {
            $result = $this->satConsultationService->consult($invoice);
        } catch (\Throwable $e) {
            Log::warning('SAT cancelability check failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }

        if (($result['estado'] ?? null) !== 'Vigente') {
            return null;
        }

        return $this->acceptanceRequired($result['esCancelable'] ?? null);
    }

    /**
     * Re-consult the SAT after the cancelation request, to detect immediate
     * cancelations when the pre-check could not determine the requirement.
     */
    private function isCanceledAtSat(Invoice $invoice): bool
    {
        try {
            return ($this->satConsultationService->consult($invoice)['estado'] ?? null) === 'Cancelado';
        } catch (\Throwable $e) {
            Log::warning('SAT post-cancelation check failed', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Whether the CFDI was stamped less than 72 hours ago. Cancelations
     * requested within that window do not require the receiver's acceptance.
     */
    private function isWithin72Hours(Invoice $invoice): bool
    {
        $stampedAt = $invoice->fecha_timbrado ?? $invoice->issued_at;

        return $stampedAt !== null && $stampedAt->gt(now()->subHours(72));
    }

    /**
     * Normalize the SAT "EsCancelable" values (accents stripped, lowercased).
     * Returns true for "Cancelable con aceptación" (acceptance required),
     * false for "Cancelable sin aceptación" and null when it is unknown.
     */
    private function acceptanceRequired(?string $value): ?bool
    {
        $normalized = mb_strtolower(Str::ascii((string) $value));

        if (str_contains($normalized, 'sin aceptacion')) {
            return false;
        }

        return str_contains($normalized, 'con aceptacion') ? true : null;
    }

    /**
     * Extract the SAT folio code from the PAC payload. The "uuid" key maps the
     * invoice UUID to its code ("201", "202", ...); newer responses use an
     * object with code/message/description.
     */
    private function extractFolioCode(array $responseData): ?string
    {
        $folios = $responseData['uuid'] ?? null;

        if (! is_array($folios) || $folios === []) {
            return null;
        }

        $folio = reset($folios);

        if (is_array($folio)) {
            $folio = $folio['code'] ?? null;
        }

        return is_scalar($folio) ? (string) $folio : null;
    }

    /**
     * Build a user-facing error message from the rejected folio entry.
     */
    private function folioErrorMessage(array $responseData, string $folioCode): string
    {
        $folios = $responseData['uuid'] ?? [];
        $folio  = is_array($folios) ? reset($folios) : null;

        $detail = is_array($folio)
            ? trim(($folio['message'] ?? '') . ' ' . ($folio['description'] ?? ''))
            : '';

        return $detail !== ''
            ? "El SAT rechazó la cancelación: {$detail}"
            : "El SAT rechazó la cancelación de la factura (código {$folioCode}).";
    }
}
