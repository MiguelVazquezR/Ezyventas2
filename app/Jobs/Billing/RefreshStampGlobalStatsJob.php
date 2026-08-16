<?php

namespace App\Jobs\Billing;

use App\Models\Billing\FiscalProfile;
use App\Models\Billing\StampGlobalStats;
use App\Services\SW\SWUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RefreshStampGlobalStatsJob
 *
 * Iterates over all active fiscal profiles, queries their live
 * stamp balance from SW Sapien, and aggregates the totals into
 * the stamp_global_stats_snapshots table.
 *
 * Scheduled to run every 30-60 minutes to keep the Global Panel
 * KPIs reasonably fresh without hammering the PAC API on every
 * page load.
 */
class RefreshStampGlobalStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     * May need adjustment depending on the number of active profiles.
     */
    public int $timeout = 300;

    public function handle(SWUserService $swUserService): void
    {
        // Global stats aggregate dealer subaccounts only — "normal" accounts
        // have a shared balance that cannot be attributed per profile here.
        $profiles = FiscalProfile::where('is_active', true)
            ->whereHas('pacAccount', function ($q) {
                $q->where('account_type', \App\Enums\PacAccountType::SUBACCOUNT)
                  ->where('status', \App\Enums\PacAccountStatus::ACTIVE);
            })
            ->with('pacAccount')
            ->get();

        $totalAssigned = 0;
        $totalUsed = 0;
        $successCount = 0;
        $failCount = 0;

        foreach ($profiles as $profile) {
            try {
                $balance = $swUserService->getStampsBalance($profile->pacAccount->sw_user_id);

                $totalAssigned += (int) ($balance['stampsAssigned'] ?? 0);
                $totalUsed += (int) ($balance['stampsUsed'] ?? 0);
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                Log::warning('RefreshStampGlobalStats: failed to query balance for profile', [
                    'fiscal_profile_id' => $profile->id,
                    'sw_user_id'        => $profile->pacAccount?->sw_user_id,
                    'error'             => $e->getMessage(),
                ]);
                // Continue with the rest — don't let one failure break the whole job
            }
        }

        StampGlobalStats::create([
            'total_stamps_assigned' => $totalAssigned,
            'total_stamps_used'     => $totalUsed,
            'active_issuers_count'  => $profiles->count(),
            'computed_at'           => now(),
        ]);

        Log::info('RefreshStampGlobalStats: snapshot computed', [
            'total_assigned'   => $totalAssigned,
            'total_used'       => $totalUsed,
            'active_issuers'   => $profiles->count(),
            'successful_calls' => $successCount,
            'failed_calls'     => $failCount,
        ]);
    }
}
