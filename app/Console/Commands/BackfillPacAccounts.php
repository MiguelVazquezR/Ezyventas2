<?php

namespace App\Console\Commands;

use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\PacAccount;
use Illuminate\Console\Command;

/**
 * Backfill legacy fiscal profiles into pac_accounts.
 *
 * For every fiscal profile with a non-null legacy sw_user_id, creates
 * an active 'subaccount' PacAccount and links it to the profile.
 *
 * Idempotent: profiles that already have pac_account_id are skipped,
 * so it is safe to re-run.
 */
class BackfillPacAccounts extends Command
{
    protected $signature = 'pac-accounts:backfill
        {--dry-run : Report what would be created without writing anything}
        {--profile= : Backfill only a specific fiscal profile ID}';

    protected $description = 'Backfill legacy fiscal_profiles.sw_user_id into the new pac_accounts table';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $profileId = $this->option('profile');

        $query = FiscalProfile::whereNotNull('sw_user_id');

        if ($profileId) {
            $query->where('id', (int) $profileId);
        }

        $profiles = $query->orderBy('id')->get();

        if ($profiles->isEmpty()) {
            $this->warn('No fiscal profiles with sw_user_id found. Nothing to backfill.');
            return self::SUCCESS;
        }

        $created  = 0;
        $skipped  = 0;

        foreach ($profiles as $profile) {
            if ($profile->pac_account_id) {
                $skipped++;
                $this->line("  [skip] profile #{$profile->id} ({$profile->rfc}) already linked to pac_account #{$profile->pac_account_id}.");
                continue;
            }

            $loginEmail = $profile->sw_account_email ?? $profile->email;

            $this->line(
                ($dryRun ? '  [dry]  ' : '  [new]  ')
                . "profile #{$profile->id} ({$profile->rfc}) -> subaccount email {$loginEmail}"
            );

            if ($dryRun) {
                $created++;
                continue;
            }

            $account = PacAccount::create([
                'subscription_id'    => $profile->subscription_id,
                'provider'           => 'sw_sapien',
                'account_type'       => PacAccountType::SUBACCOUNT,
                'status'             => PacAccountStatus::ACTIVE,
                'sw_user_id'         => $profile->sw_user_id,
                'login_email'        => $loginEmail,
                'password'           => $profile->password, // encrypted cast handles it
                'requested_at'       => $profile->created_at,
                'activated_at'       => now(),
            ]);

            $profile->update(['pac_account_id' => $account->id]);

            $created++;
        }

        $verb = $dryRun ? 'Would create' : 'Created';
        $this->info("{$verb} {$created} pac_account(s). Skipped {$skipped} already-linked profile(s).");

        return self::SUCCESS;
    }
}
