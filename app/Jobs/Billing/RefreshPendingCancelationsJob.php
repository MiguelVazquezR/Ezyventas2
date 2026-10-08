<?php

namespace App\Jobs\Billing;

use App\Actions\Billing\RefreshCancelationStatusAction;
use App\Enums\InvoiceStatus;
use App\Mail\CancelationResolvedNotification;
use App\Models\Billing\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Periodically re-checks every invoice waiting for the receiver's cancelation
 * acceptance against the SAT (T301) so the status updates without the user
 * pressing "Verificar estatus", and notifies the subscriber by email when the
 * request is resolved (T302).
 *
 * Invoices whose acceptance deadline already expired ("Plazo vencido") are
 * skipped — they cannot change on their own and the user must re-request the
 * cancelation. The subscriber is notified once when the deadline expires.
 */
class RefreshPendingCancelationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RefreshCancelationStatusAction $refreshAction): void
    {
        $invoices = Invoice::query()
            ->where('status', InvoiceStatus::CANCELATION_PENDING)
            ->where(function ($query) {
                $query->whereNull('cancelation_status')
                    ->orWhere('cancelation_status', '!=', 'Plazo vencido');
            })
            ->get();

        $checked  = 0;
        $resolved = 0;
        $notified = 0;

        foreach ($invoices as $invoice) {
            try {
                $previousCancelationStatus = $invoice->cancelation_status;

                $result = $refreshAction->execute($invoice);

                if ($result === null) {
                    continue; // Throttled — checked recently (manual check or previous run).
                }

                $checked++;

                if (in_array($result, ['canceled', 'rejected'], true)) {
                    $resolved++;
                    $notified += $this->notifySubscriber($invoice->fresh(['branch.subscription']), $result) ? 1 : 0;
                } elseif ($result === 'expired' && $previousCancelationStatus !== 'Plazo vencido') {
                    // The invoice stays pending (the user must re-request), but
                    // the deadline just expired — notify once.
                    $notified += $this->notifySubscriber($invoice->fresh(['branch.subscription']), $result) ? 1 : 0;
                }
            } catch (\Throwable $e) {
                // One unreachable SAT response must not stop the whole batch.
                Log::warning('Pending cancelation refresh failed', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        Log::info('Pending cancelations refreshed', [
            'pending'  => $invoices->count(),
            'checked'  => $checked,
            'resolved' => $resolved,
            'notified' => $notified,
        ]);
    }

    /**
     * Email the subscriber about the resolution (best effort, production only).
     */
    private function notifySubscriber(?Invoice $invoice, string $result): bool
    {
        if (! $invoice) {
            return false;
        }

        try {
            $email = $invoice->branch?->subscription?->contact_email;

            if (! $email || ! app()->environment('production')) {
                return false;
            }

            Mail::to($email)->send(new CancelationResolvedNotification($invoice, $result));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send cancelation resolved email', [
                'invoice_id' => $invoice->id,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }
}
