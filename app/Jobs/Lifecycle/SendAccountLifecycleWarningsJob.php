<?php

namespace App\Jobs\Lifecycle;

use App\Actions\Lifecycle\SendAccountDeletionWarningAction;
use App\Models\Subscription;
use App\Services\Lifecycle\AccountLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily run that emails the accounts entering the warning window
 * (default: 7 days before the purge).
 *
 * One failing account never stops the rest of the batch.
 */
class SendAccountLifecycleWarningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        AccountLifecycleService $lifecycleService,
        SendAccountDeletionWarningAction $warningAction,
    ): void {
        if (! config('account_lifecycle.inactivity.enabled')) {
            return;
        }

        $subscriptions = $lifecycleService->subscriptionsDueForWarning();
        $sent = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $sent += $warningAction->execute($subscription) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::error('Failed to send account inactivity warning', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Account inactivity warnings processed', [
            'subscriptions' => $subscriptions->count(),
            'emails_sent' => $sent,
        ]);
    }
}
