<?php

namespace App\Jobs\Billing;

use App\Mail\PreventiveBillingAlertNotification;
use App\Models\Billing\FiscalProfile;
use App\Services\SW\SWUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily preventive alerts (Fase 3, T304/T305):
 *  - Low stamp balance on any active fiscal profile.
 *  - CSD about to expire (configured milestones) or already expired.
 *
 * Issues are grouped per subscription so each business receives ONE email.
 * A mailbox issue repeats only after a cooldown (Cache markers), and emails
 * are sent only in production — same convention as the reconciliation alert.
 */
class CheckPreventiveAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cooldown for repeated low-balance emails (days). */
    private const LOW_BALANCE_COOLDOWN_DAYS = 3;

    /** Expired CSD reminders are repeated after this many days. */
    private const EXPIRED_CSD_REMINDER_DAYS = 30;

    /** Milestone alerts (e.g. 30/15/5 days left) are sent once. */
    private const MILESTONE_REMINDER_DAYS = 180;

    public function handle(SWUserService $swUserService): void
    {
        $threshold  = (int) config('billing.low_stamp_threshold', 5);
        $milestones = (array) config('billing.csd_expiry_warning_days', [30, 15, 5]);

        $profiles = FiscalProfile::query()
            ->where('is_active', true)
            ->with(['subscription', 'pacAccount'])
            ->get();

        // Collect issues per subscription so each business gets ONE email.
        $bySubscription = [];

        foreach ($profiles as $profile) {
            $issues = array_merge(
                $this->lowBalanceIssues($profile, $swUserService, $threshold),
                $this->csdIssues($profile, $milestones),
            );

            if ($issues === []) {
                continue;
            }

            $subscription = $profile->subscription;

            if (! $subscription?->contact_email) {
                continue;
            }

            $bySubscription[$subscription->id] ??= [
                'name'   => $subscription->commercial_name ?: ('Suscripción #' . $subscription->id),
                'email'  => $subscription->contact_email,
                'issues' => [],
            ];

            $bySubscription[$subscription->id]['issues'] = array_merge(
                $bySubscription[$subscription->id]['issues'],
                $issues,
            );
        }

        $sent = 0;

        foreach ($bySubscription as $group) {
            $sent += $this->send($group) ? 1 : 0;
        }

        Log::info('Preventive billing alerts checked', [
            'profiles'      => $profiles->count(),
            'subscriptions' => count($bySubscription),
            'emails_sent'   => $sent,
        ]);
    }

    /**
     * Low stamp balance issues for one profile (respecting the cooldown).
     *
     * @return array<int, array<string, mixed>>
     */
    private function lowBalanceIssues(FiscalProfile $profile, SWUserService $swUserService, int $threshold): array
    {
        try {
            [$balance] = $profile->stampBalance($swUserService);
        } catch (\Throwable $e) {
            Log::warning('Preventive alerts: could not resolve stamp balance', [
                'fiscal_profile_id' => $profile->id,
                'error'             => $e->getMessage(),
            ]);

            return [];
        }

        $available = $balance['stampsBalance'] ?? null;

        if ($available === null || (int) $available > $threshold) {
            return [];
        }

        $dedupKey = "billing.low_balance_alert.{$profile->id}";

        if (Cache::has($dedupKey)) {
            return [];
        }

        return [[
            'type'           => 'low_balance',
            'rfc'            => $profile->rfc,
            'balance'        => (int) $available,
            'threshold'      => $threshold,
            'dedup_key'      => $dedupKey,
            'dedup_ttl_days' => self::LOW_BALANCE_COOLDOWN_DAYS,
        ]];
    }

    /**
     * CSD expiry issues for one profile: milestone alerts (once each) plus a
     * repeated reminder while the certificate is expired.
     *
     * @param  array<int, int>  $milestones
     * @return array<int, array<string, mixed>>
     */
    private function csdIssues(FiscalProfile $profile, array $milestones): array
    {
        if (! $profile->valid_to) {
            return [];
        }

        try {
            $validTo = Carbon::parse($profile->valid_to)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($validTo, false);

        if ($daysLeft <= 0) {
            $dedupKey = "billing.csd_alert.{$profile->id}.expired";

            if (Cache::has($dedupKey)) {
                return [];
            }

            return [[
                'type'           => 'csd_expired',
                'rfc'            => $profile->rfc,
                'valid_to'       => $validTo->toDateString(),
                'dedup_key'      => $dedupKey,
                'dedup_ttl_days' => self::EXPIRED_CSD_REMINDER_DAYS,
            ]];
        }

        if (! in_array($daysLeft, $milestones, true)) {
            return [];
        }

        $dedupKey = "billing.csd_alert.{$profile->id}.{$daysLeft}";

        if (Cache::has($dedupKey)) {
            return [];
        }

        return [[
            'type'           => 'csd_expiring',
            'rfc'            => $profile->rfc,
            'days_left'      => $daysLeft,
            'valid_to'       => $validTo->toDateString(),
            'dedup_key'      => $dedupKey,
            'dedup_ttl_days' => self::MILESTONE_REMINDER_DAYS,
        ]];
    }

    /**
     * Send the grouped email (production only). Dedup markers are only applied
     * after a successful send, so a failed delivery is retried next run.
     *
     * @param  array{name: string, email: string, issues: array<int, array<string, mixed>>}  $group
     */
    private function send(array $group): bool
    {
        try {
            if (! app()->environment('production')) {
                return false;
            }

            Mail::to($group['email'])->send(
                new PreventiveBillingAlertNotification($group['name'], $group['issues'])
            );

            foreach ($group['issues'] as $issue) {
                if (! empty($issue['dedup_key'])) {
                    Cache::put(
                        $issue['dedup_key'],
                        now()->toIso8601String(),
                        now()->addDays((int) ($issue['dedup_ttl_days'] ?? self::LOW_BALANCE_COOLDOWN_DAYS)),
                    );
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send preventive billing alerts', [
                'email' => $group['email'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
