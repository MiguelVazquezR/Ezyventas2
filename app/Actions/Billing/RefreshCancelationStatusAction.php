<?php

namespace App\Actions\Billing;

use App\Models\Billing\Invoice;
use App\Models\Transaction;
use App\Services\Billing\SatConsultationService;

/**
 * Re-checks a cancelation that is awaiting the receiver's response against the
 * SAT and applies the outcome to the invoice: updates the stored cancelation
 * status and releases the linked POS sale when the cancelation was accepted.
 */
class RefreshCancelationStatusAction
{
    /**
     * Minutes between automatic checks, to avoid hitting the SAT on every page load.
     */
    public const RECHECK_MINUTES = 5;

    public function __construct(
        private readonly SatConsultationService $satService,
    ) {}

    /**
     * @param  bool  $throttle  Skip the SAT call when the last check is recent.
     * @return string|null 'canceled' | 'rejected' | 'expired' | 'pending',
     *                     or null when the check was skipped by the throttle.
     *
     * @throws \RuntimeException When the SAT cannot be queried.
     */
    public function execute(Invoice $invoice, bool $throttle = true): ?string
    {
        if ($throttle && $this->wasRecentlyChecked($invoice)) {
            return null;
        }

        try {
            $result = $this->satService->applyResult($invoice, $this->satService->consult($invoice));

            if ($result === 'canceled' && $invoice->transaction_id) {
                // Release the linked POS sale so it can be invoiced again.
                Transaction::where('id', $invoice->transaction_id)->update(['invoiced' => false]);
            }

            return $result;
        } finally {
            // Record the attempt even on failure, so a SAT outage does not make
            // every visit to a pending invoice wait for the HTTP timeout.
            $invoice->update(['cancelation_last_checked_at' => now()]);
        }
    }

    private function wasRecentlyChecked(Invoice $invoice): bool
    {
        return $invoice->cancelation_last_checked_at !== null
            && $invoice->cancelation_last_checked_at->gt(now()->subMinutes(self::RECHECK_MINUTES));
    }
}
