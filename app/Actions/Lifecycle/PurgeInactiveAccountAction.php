<?php

namespace App\Actions\Lifecycle;

use App\Models\Subscription;
use App\Services\Lifecycle\TenantDataPurgeService;
use Illuminate\Support\Facades\Log;

/**
 * Purge the business data of one inactive account.
 *
 * The account stays usable: subscription, branches and the owner remain, so
 * the next sign-in starts with an empty business instead of a dead account.
 * Accounts already purged are skipped, which makes the action idempotent even
 * if a job runs twice.
 */
class PurgeInactiveAccountAction
{
    public function __construct(private readonly TenantDataPurgeService $purgeService) {}

    /**
     * @return array<string, int> Deleted rows per entity (empty when skipped).
     */
    public function execute(Subscription $subscription): array
    {
        if ($subscription->data_purged_at) {
            return [];
        }

        $summary = $this->purgeService->purge($subscription);

        $subscription->forceFill(['data_purged_at' => now()])->saveQuietly();

        Log::info('Inactive account data purged', [
            'subscription_id' => $subscription->id,
            'deleted' => $summary,
        ]);

        return $summary;
    }
}
