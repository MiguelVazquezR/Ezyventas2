<?php

namespace App\Services\CashRegisters;

use App\Enums\CashRegisterSessionStatus;
use App\Events\SessionClosed;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lifecycle of a cash register session (shift): opening it, joining it, leaving
 * it, retaking a shift without counting the fund again and closing it (the cut).
 *
 * Shared by the web POS and the mobile app so both clients open, join and close
 * the register with exactly the same rules, snapshots and broadcasts.
 */
class CashRegisterSessionLifecycleService
{
    /**
     * Opens a session on a free terminal.
     *
     * The balances declared by the cashier are applied first, so the opening
     * snapshot holds what was declared and not the previous raw balance.
     * Accounts the cashier did not declare inherit the balance of the last
     * closed cut of that terminal (fallback: their current balance).
     *
     * @param  array<int, array{id?: int|string, balance?: int|float|string}>  $declaredBankAccounts
     */
    public function open(
        CashRegister $cashRegister,
        User $user,
        float $openingCashBalance,
        array $declaredBankAccounts = [],
    ): CashRegisterSession {
        return DB::transaction(function () use ($cashRegister, $user, $openingCashBalance, $declaredBankAccounts) {
            $declaredAccountIds = [];

            foreach ($declaredBankAccounts as $accountData) {
                $bankAccount = BankAccount::find($accountData['id'] ?? null);

                if ($bankAccount) {
                    $declaredAccountIds[] = $bankAccount->id;
                    $bankAccount->update(['balance' => (float) ($accountData['balance'] ?? 0)]);
                }
            }

            $session = $cashRegister->sessions()->create([
                'user_id' => $user->id,
                'opening_cash_balance' => $openingCashBalance,
                'opening_bank_balances' => $this->snapshotBankBalances($cashRegister, $declaredAccountIds),
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            $session->users()->syncWithoutDetaching([$user->id]);
            $cashRegister->update(['in_use' => true]);

            return $session;
        });
    }

    /**
     * Session currently open on the terminal (with its opener), if any.
     */
    public function openSessionOn(CashRegister $cashRegister): ?CashRegisterSession
    {
        return $cashRegister->sessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->with('opener:id,name')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Adds the user to an open session so their sales belong to that shift.
     */
    public function join(CashRegisterSession $session, User $user): void
    {
        $session->users()->syncWithoutDetaching([$user->id]);
    }

    /**
     * Removes the user from the shift without closing it (the register keeps
     * working for the rest of the team).
     */
    public function leave(CashRegisterSession $session, User $user): void
    {
        $session->users()->detach($user->id);
    }

    /**
     * Retakes a shift without asking for the cash fund again: joins the session
     * that is open on the terminal, or starts a new one inheriting the closing
     * balances of the last cut of that terminal.
     */
    public function rejoinOrStart(CashRegister $cashRegister, User $user, User $opener): CashRegisterSession
    {
        $existingSession = $this->openSessionOn($cashRegister);

        if ($existingSession) {
            $this->join($existingSession, $user);

            return $existingSession;
        }

        return DB::transaction(function () use ($cashRegister, $user, $opener) {
            $lastClosedSession = $cashRegister->sessions()
                ->where('status', CashRegisterSessionStatus::CLOSED)
                ->latest('closed_at')
                ->first();

            $previousBalances = collect($lastClosedSession?->closing_bank_balances ?? [])->keyBy('id');

            $openingBankBalances = BankAccount::whereHas('branches', fn ($query) => $query->where('branch_id', $cashRegister->branch_id))
                ->get()
                ->map(function (BankAccount $account) use ($previousBalances) {
                    $previous = $previousBalances->get($account->id);

                    return [
                        'id' => $account->id,
                        'account_name' => $account->account_name,
                        'bank_name' => $account->bank_name,
                        'balance' => (float) ($previous['balance'] ?? $account->balance),
                    ];
                })
                ->values()
                ->all();

            $session = $cashRegister->sessions()->create([
                'user_id' => $opener->id,
                'opening_cash_balance' => (float) ($lastClosedSession?->closing_cash_balance ?? 0),
                'opening_bank_balances' => $openingBankBalances,
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            $session->users()->attach(array_unique([$opener->id, $user->id]));
            $cashRegister->update(['in_use' => true]);

            return $session;
        });
    }

    /**
     * Closes the shift (the cut): the model reconciles the bank balances and the
     * cash difference, and every other device is notified through the broadcast.
     */
    public function close(CashRegisterSession $session, float $closingCashBalance, ?string $notes, User $closingUser): void
    {
        DB::transaction(function () use ($session, $closingCashBalance, $notes, $closingUser) {
            $session->closeSession($closingCashBalance, $notes);

            DB::afterCommit(function () use ($session, $closingUser) {
                Log::info('Broadcasting SessionClosed event for session ID: ' . $session->id);
                broadcast(new SessionClosed($session, $closingUser))->toOthers();
            });
        });
    }

    /**
     * @param  array<int, int>  $declaredAccountIds
     * @return array<int, array<string, mixed>>
     */
    private function snapshotBankBalances(CashRegister $cashRegister, array $declaredAccountIds): array
    {
        $lastClosedSession = $cashRegister->sessions()
            ->where('status', CashRegisterSessionStatus::CLOSED)
            ->latest('closed_at')
            ->first();

        $previousBalances = collect($lastClosedSession?->closing_bank_balances ?? [])->keyBy('id');

        return BankAccount::whereHas('branches', fn ($query) => $query->where('branch_id', $cashRegister->branch_id))
            ->get()
            ->map(function (BankAccount $account) use ($previousBalances, $declaredAccountIds) {
                $previous = $previousBalances->get($account->id);

                return [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'bank_name' => $account->bank_name,
                    'balance' => in_array($account->id, $declaredAccountIds, true)
                        ? (float) $account->balance
                        : (float) ($previous['balance'] ?? $account->balance),
                ];
            })
            ->values()
            ->all();
    }
}
