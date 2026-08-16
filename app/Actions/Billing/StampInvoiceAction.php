<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InsufficientStampsException;
use App\Exceptions\Billing\PacDuplicateContentException;
use App\Exceptions\Billing\PacTimeoutOrAmbiguousException;
use App\Exceptions\Billing\PacValidationException;
use App\Jobs\Billing\ResolveAmbiguousStampJob;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\Billing\StampReservation;
use App\Services\Billing\SWSapienService;
use App\Services\Billing\WalletService;
use App\Services\SW\SWUserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * StampInvoiceAction
 *
 * Orchestrates the stamping flow with a reservation, replacing the direct
 * synchronous stamp() calls:
 *
 *  1. In a DB transaction: lock the fiscal profile row, assign an atomic
 *     folio (if missing), verify available balance by account type, and
 *     create the reservation BEFORE calling the PAC.
 *  2. Outside the transaction (the HTTP call must not hold the lock):
 *     call the PAC with the reservation's customid and resolve the outcome.
 *
 * Reservation semantics (both account types):
 *  - normal: protects the shared-pool balance.
 *  - subaccount: the PAC already guards the balance, but the customid gives
 *    idempotency to survive timeouts without duplicating or losing stamps.
 *
 * An ambiguous reservation is NEVER auto-released — it escalates to manual
 * review if automatic retries are exhausted.
 */
class StampInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
        private readonly SWUserService $swUserService,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Stamp an invoice using the reservation flow.
     *
     * @throws InsufficientStampsException When there are no stamps available.
     * @throws PacValidationException      Clear CFDI validation error (invoice returns to DRAFT).
     * @throws \RuntimeException           Other errors.
     */
    public function execute(Invoice $invoice): void
    {
        $reservation = DB::transaction(function () use ($invoice) {
            // 1. Lock the fiscal profile row — serializes any concurrent
            //    attempt for THIS profile (double click, network retry, etc.).
            $profile = FiscalProfile::where('id', $invoice->fiscal_profile_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                throw new \RuntimeException('La factura no tiene un perfil fiscal asociado.');
            }

            $account = $profile->pacAccount;

            if (! $account || ! $account->isActive()) {
                throw new \RuntimeException('La cuenta PAC del emisor no está activa. Espera a que se complete la activación.');
            }

            // 2. Atomic folio (only if the invoice has none yet — drafts created
            //    through createInvoice already hold one from the counter).
            //    The folio is per (branch, series), and the branch lives on the
            //    invoice (a FiscalProfile has no branch_id).
            if (empty($invoice->folio)) {
                $invoice->folio = (string) $this->swService->reserveNextFolio($invoice->branch_id, $invoice->series);
            }

            // 3. Verify available balance according to the account type.
            $available = $this->resolveAvailableBalance($profile, $account);

            if ($available < 1) {
                // No reservation is created and the folio counter is not touched.
                throw new InsufficientStampsException(
                    'No tienes timbres suficientes para timbrar esta factura. Compra más timbres para continuar.'
                );
            }

            // 4. Create the reservation BEFORE calling the PAC.
            $customid = (string) Str::uuid();

            $reservation = StampReservation::create([
                'fiscal_profile_id' => $profile->id,
                'reference_type'    => Invoice::class,
                'reference_id'      => $invoice->id,
                'customid'          => $customid,
                'quantity'          => 1,
                'status'            => 'held',
            ]);

            $invoice->status = InvoiceStatus::PENDING;
            $invoice->save();

            return $reservation;
        });

        // HTTP call OUTSIDE the DB transaction — the profile row lock is released.
        $this->callPac($invoice, $reservation);
    }

    /**
     * Resolve the available balance for a profile based on its account type.
     */
    private function resolveAvailableBalance(FiscalProfile $profile, $account): int
    {
        if ($account->isSubaccount()) {
            try {
                $balance = $this->swUserService->getStampsBalance($account->sw_user_id);

                return (int) ($balance['stampsBalance'] ?? 0);
            } catch (\Throwable $e) {
                // If the PAC balance query is unavailable, don't block stamping —
                // the PAC itself rejects when the subaccount runs out of stamps.
                Log::warning('Balance query failed — allowing stamping to proceed', [
                    'fiscal_profile_id' => $profile->id,
                    'error'             => $e->getMessage(),
                ]);

                return 1;
            }
        }

        return $this->walletService->availableBalance($profile->id);
    }

    /**
     * The real PAC call. Handles every outcome and resolves the reservation.
     */
    private function callPac(Invoice $invoice, StampReservation $reservation): void
    {
        try {
            $response = $this->swService->stamp($invoice, customid: $reservation->customid);

            $this->confirm($reservation, $invoice, $response);
        } catch (PacValidationException $e) {
            // Clear CFDI validation error — NOT ambiguous.
            $reservation->update([
                'status'            => 'released',
                'released_at'       => now(),
                'last_pac_response' => $e->response ?: null,
            ]);

            $invoice->update([
                'status' => InvoiceStatus::DRAFT, // regresa a editable, folio se conserva (no se reusa)
            ]);

            throw $e;
        } catch (PacDuplicateContentException $e) {
            $data = $e->response['data'] ?? [];

            if (empty($data['cfdi'])) {
                // CFDI3307 — partial data, the full CFDI cannot be auto-recovered.
                // Mandatory manual review (spec §5.4).
                $reservation->update([
                    'status'            => 'manual_review',
                    'last_pac_response' => $e->response ?: null,
                ]);

                $invoice->update([
                    'status'                 => InvoiceStatus::AWAITING_VERIFICATION,
                    'requires_manual_review' => true,
                ]);

                Log::warning('Stamp reservation escalated to manual review (partial duplicate response)', [
                    'stamp_reservation_id' => $reservation->id,
                    'invoice_id'           => $invoice->id,
                ]);

                return;
            }

            // "307 — timbre previo": successful recovery with complete data.
            $this->confirm($reservation, $invoice, $data);
        } catch (PacTimeoutOrAmbiguousException $e) {
            // We don't know if the PAC stamped or not. Never auto-release.
            $reservation->update([
                'status'            => 'ambiguous',
                'last_pac_response' => $e->response ?: null,
            ]);

            $invoice->update([
                'status' => InvoiceStatus::AWAITING_VERIFICATION,
            ]);

            ResolveAmbiguousStampJob::dispatch($reservation->id);
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

            $this->swService->persistStampedInvoice($invoice, $response);

            // The StampMovementObserver already records the 'exit' automatically
            // when it detects Invoice::updated with status CERTIFIED.
        });
    }
}
