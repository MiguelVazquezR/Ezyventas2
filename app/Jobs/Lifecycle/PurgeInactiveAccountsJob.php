<?php

namespace App\Jobs\Lifecycle;

use App\Actions\Lifecycle\PurgeInactiveAccountAction;
use App\Services\Lifecycle\AccountLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily run that purges the business data of accounts without sign-ins for
 * longer than the configured window (default: 90 days).
 *
 * One failing account never stops the rest of the batch; the failed account
 * keeps data_purged_at empty and is retried on the next run.
 */
class PurgeInactiveAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(
        AccountLifecycleService $lifecycleService,
        PurgeInactiveAccountAction $purgeAction,
    ): void {
        if (! config('account_lifecycle.inactivity.enabled')) {
            return;
        }

        $subscriptions = $lifecycleService->subscriptionsDueForPurge();
        $purged = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $purgeAction->execute($subscription);
                $purged++;
            } catch (\Throwable $e) {
                Log::error('Failed to purge inactive account', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Inactive accounts purge processed', [
            'subscriptions' => $subscriptions->count(),
            'accounts_purged' => $purged,
        ]);
    }
}
