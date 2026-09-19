<?php

namespace App\Services\BankAccounts;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reads the bank accounts a user may declare when opening the cash register.
 *
 * Subscription owners (users without roles) see every account of the branch;
 * employees only see the accounts assigned to them, exactly like the web POS.
 */
class BankAccountQueryService
{
    /**
     * @return Collection<int, BankAccount>
     */
    public function forUser(User $user): Collection
    {
        $user->loadMissing('branch');

        $accounts = $user->roles()->exists()
            ? $user->bankAccounts()
            : $user->branch->bankAccounts();

        return $accounts->orderBy('bank_name')->get();
    }

    /**
     * @param  Collection<int, BankAccount>  $accounts
     * @return array<int, array<string, mixed>>
     */
    public function payloadCollection(Collection $accounts): array
    {
        return $accounts->map(fn (BankAccount $account) => $this->payload($account))->values()->all();
    }

    /**
     * @return array{id: int, name: string, bank_name: string|null, account_name: string|null, balance: string}
     */
    public function payload(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $this->displayName($account),
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'balance' => (string) $account->balance,
        ];
    }

    /**
     * Human readable label: "Cuenta principal - BBVA (...4471)".
     */
    private function displayName(BankAccount $account): string
    {
        $label = trim(($account->account_name ?? '') . ' - ' . ($account->bank_name ?? ''), ' -');

        $number = $account->card_number ?: ($account->account_number ?: $account->clabe);
        $lastDigits = $number ? substr((string) $number, -4) : null;

        if (!$lastDigits) {
            return $label;
        }

        return "{$label} (...{$lastDigits})";
    }
}
