<?php

namespace App\Actions\Lifecycle;

use App\Mail\InactiveAccountWarningMail;
use App\Models\Subscription;
use App\Services\Lifecycle\AccountLifecycleService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Warn the account owner that the business data is about to be deleted.
 *
 * The marker is only stored after a successful send, so a delivery failure is
 * retried on the next run instead of silently skipping the warning.
 */
class SendAccountDeletionWarningAction
{
    public function __construct(private readonly AccountLifecycleService $lifecycleService) {}

    public function execute(Subscription $subscription): bool
    {
        $recipient = $this->resolveRecipient($subscription);

        if (! $recipient) {
            Log::warning('Account deletion warning skipped: no recipient email', [
                'subscription_id' => $subscription->id,
            ]);

            return false;
        }

        $purgeDate = $this->lifecycleService->purgeDate($subscription);

        Mail::to($recipient)->send(new InactiveAccountWarningMail(
            subscriptionName: $this->accountName($subscription),
            purgeDate: $purgeDate->format('d/m/Y'),
            daysUntilPurge: max(1, $this->lifecycleService->daysUntilPurge($subscription)),
        ));

        $subscription->forceFill(['inactivity_warned_at' => now()])->saveQuietly();

        Log::info('Account deletion warning sent', [
            'subscription_id' => $subscription->id,
            'email' => $recipient,
            'purge_date' => $purgeDate->toDateString(),
        ]);

        return true;
    }

    /**
     * The owner is the account's primary user; the commercial contact address
     * is the fallback when the owner has no personal email.
     */
    private function resolveRecipient(Subscription $subscription): ?string
    {
        $owner = $this->lifecycleService->resolvePrimaryUser($subscription);

        return $owner?->email ?: $subscription->contact_email;
    }

    private function accountName(Subscription $subscription): string
    {
        return $subscription->commercial_name ?: ('Suscripción #' . $subscription->id);
    }
}
