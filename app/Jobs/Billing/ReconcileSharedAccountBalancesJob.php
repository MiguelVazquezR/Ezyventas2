<?php

namespace App\Jobs\Billing;

use App\Enums\PacAccountStatus;
use App\Enums\PacAccountType;
use App\Mail\AdminReconciliationAlertNotification;
use App\Models\Billing\PacAccount;
use App\Models\Billing\PacCallLog;
use App\Models\User;
use App\Services\Billing\WalletService;
use App\Services\SW\SWUserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * ReconcileSharedAccountBalancesJob
 *
 * Daily job (separate from RefreshStampGlobalStatsJob) that compares, for every
 * active shared "normal" PAC account, the expected local balance (sum of
 * WalletService::availableBalance() minus welcome/gift stamps across the
 * account's fiscal profiles) against the real balance via getOwnBalance().
 *
 * Any difference is logged, recorded in pac_call_logs (audit) and alerts the
 * superadmin immediately (email in production).
 */
class ReconcileSharedAccountBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(SWUserService $swUserService, WalletService $walletService): void
    {
        $accounts = PacAccount::where('account_type', PacAccountType::SHARED)
            ->where('status', PacAccountStatus::ACTIVE)
            ->with('fiscalProfiles')
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        foreach ($accounts as $account) {
            // Saldo esperado local = suma de la wallet de cada perfil de la cuenta.
            // Los timbres de regalo (welcome) NO existen en el PAC real: se
            // excluyen del esperado para no generar falsos mismatches.
            $expected = 0;
            foreach ($account->fiscalProfiles as $profile) {
                $wallet = $walletService->availableBalance($profile->id);
                $gift   = $walletService->welcomeStampsGranted($profile->id);

                $expected += max($wallet - $gift, 0);
            }

            try {
                $balance = $swUserService->getOwnBalance($account);
                $real = (int) ($balance['stampsBalance'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning('Reconciliation: could not query real balance', [
                    'pac_account_id'  => $account->id,
                    'subscription_id' => $account->subscription_id,
                    'error'           => $e->getMessage(),
                ]);
                continue;
            }

            $difference = $real - $expected;

            // Auditoría (sin credenciales ni datos sensibles).
            PacCallLog::create([
                'pac_account_id'         => $account->id,
                'operation'              => 'reconcile',
                'request_payload'        => ['expected_balance' => $expected],
                'response_body'          => ['real_balance' => $real, 'difference' => $difference],
                'response_status_code'   => 200,
            ]);

            Log::info('Reconciliation result', [
                'pac_account_id' => $account->id,
                'subscription_id' => $account->subscription_id,
                'expected'       => $expected,
                'real'           => $real,
                'difference'     => $difference,
            ]);

            if ($difference !== 0) {
                $this->alertAdmin($account, $expected, $real, $difference);
            }
        }
    }

    /**
     * Alert the superadmin of a reconciliation mismatch (best effort).
     */
    private function alertAdmin(PacAccount $account, int $expected, int $real, int $difference): void
    {
        Log::error('STAMP RECONCILIATION MISMATCH — shared account differs from PAC', [
            'pac_account_id'  => $account->id,
            'subscription_id' => $account->subscription_id,
            'expected'        => $expected,
            'real'            => $real,
            'difference'      => $difference,
        ]);

        try {
            $adminUser = User::whereHas('branch', fn ($q) => $q->where('subscription_id', 1))
                ->select('email')
                ->first();

            if (! $adminUser || ! app()->environment('production')) {
                return;
            }

            Mail::to($adminUser->email)->send(
                new AdminReconciliationAlertNotification(
                    $account->id,
                    $account->subscription?->commercial_name ?? '—',
                    $expected,
                    $real,
                    $difference,
                )
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send reconciliation alert', [
                'pac_account_id' => $account->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }
}
