<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\PacDuplicateContentException;
use App\Exceptions\Billing\PacTimeoutOrAmbiguousException;
use App\Exceptions\Billing\PacValidationException;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampReservation;
use App\Services\Billing\SWSapienService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ResolveAmbiguousStampJob
 *
 * Retries an ambiguous stamp reservation with the SAME customid and payload:
 *  - If the PAC had actually stamped, it responds "307" with the complete
 *    data (recovered — no extra stamp consumed).
 *  - If the stamping genuinely never arrived, it stamps fresh.
 *
 * NEVER releases the reservation automatically on persistent ambiguity: after
 * the maximum attempts it escalates to 'manual_review' so an admin decides.
 * A CLEAR rejection (PacValidationException) IS released — that is not
 * ambiguous.
 */
class ResolveAmbiguousStampJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [10, 30, 60, 120, 300]; // backoff creciente

    public function __construct(
        public int $reservationId,
    ) {}

    public function uniqueId(): string
    {
        return 'resolve-stamp-reservation-' . $this->reservationId;
    }

    public function handle(SWSapienService $swService): void
    {
        $reservation = StampReservation::find($this->reservationId);

        if (! $reservation || $reservation->status !== 'ambiguous') {
            // Ya se resolvió por otro camino — no hacer nada.
            return;
        }

        $invoice = $reservation->reference;

        if (! $invoice instanceof Invoice) {
            // La referencia se perdió — no hay forma de resolver: revisión manual.
            $reservation->update([
                'status'            => 'manual_review',
                'last_pac_response' => ['error' => 'La factura de referencia ya no existe.'],
            ]);

            Log::warning('Ambiguous reservation escalated to manual review (lost reference)', [
                'stamp_reservation_id' => $reservation->id,
            ]);

            return;
        }

        $reservation->increment('attempts');

        try {
            // MISMO customid, MISMO payload — el PAC responde 307 con los datos
            // completos si ya había timbrado, o timbra de cero si nunca llegó.
            $response = $swService->stamp($invoice, customid: $reservation->customid);

            $this->confirm($reservation, $invoice, $response);
        } catch (PacDuplicateContentException $e) {
            $data = $e->response['data'] ?? [];

            if (empty($data['cfdi'])) {
                // CFDI3307 — datos parciales, no se puede auto-recuperar el CFDI.
                $this->escalateToManualReview($reservation, $invoice, $e->response);
                return;
            }

            // 307 — recuperación exitosa con datos completos.
            $this->confirm($reservation, $invoice, $data);
        } catch (PacValidationException $e) {
            // Rechazo claro en el reintento — NO es ambiguo: liberar y regresar a DRAFT.
            $reservation->update([
                'status'            => 'released',
                'released_at'       => now(),
                'last_pac_response' => $e->response ?: null,
            ]);

            $invoice->update([
                'status' => InvoiceStatus::DRAFT,
            ]);

            Log::info('Ambiguous reservation released on clear validation error', [
                'stamp_reservation_id' => $reservation->id,
                'invoice_id'           => $invoice->id,
            ]);
        } catch (PacTimeoutOrAmbiguousException $e) {
            $reservation->update([
                'last_pac_response' => $e->response ?: null,
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->escalateToManualReview($reservation, $invoice, $e->response);
                return;
            }

            // Laravel reintenta según $backoff.
            $this->release($e->getMessage());
        } catch (\Throwable $e) {
            // Error inesperado — tratar como ambiguo para no liberar por accidente.
            Log::error('Unexpected error while resolving ambiguous stamp', [
                'stamp_reservation_id' => $reservation->id,
                'invoice_id'           => $invoice->id,
                'error'                => $e->getMessage(),
            ]);

            $reservation->update([
                'last_pac_response' => ['error' => $e->getMessage()],
            ]);

            if ($this->attempts() >= $this->tries) {
                $this->escalateToManualReview($reservation, $invoice, ['error' => $e->getMessage()]);
                return;
            }

            $this->release($e->getMessage());
        }
    }

    /**
     * Mark a reservation as confirmed and persist the stamped invoice.
     */
    private function confirm(StampReservation $reservation, Invoice $invoice, array $response): void
    {
        DB::transaction(function () use ($reservation, $invoice, $response) {
            $reservation->update([
                'status'            => 'confirmed',
                'confirmed_at'      => now(),
                'last_pac_response' => $response,
            ]);

            $swService = app(SWSapienService::class);
            $swService->persistStampedInvoice($invoice, $response);

            // El StampMovementObserver registra el 'exit' automáticamente al
            // detectar Invoice::updated con status CERTIFIED.
        });

        Log::info('Ambiguous reservation resolved and confirmed', [
            'stamp_reservation_id' => $reservation->id,
            'invoice_id'           => $invoice->id,
        ]);
    }

    /**
     * Escalate to manual_review (admin must decide — never auto-release).
     */
    private function escalateToManualReview(StampReservation $reservation, Invoice $invoice, ?array $lastResponse): void
    {
        $reservation->update([
            'status'            => 'manual_review',
            'last_pac_response' => $lastResponse ?: null,
        ]);

        $invoice->update([
            'status'                 => InvoiceStatus::AWAITING_VERIFICATION,
            'requires_manual_review' => true,
        ]);

        Log::warning('Stamp reservation escalated to manual review', [
            'stamp_reservation_id' => $reservation->id,
            'invoice_id'           => $invoice->id,
        ]);
    }
}

