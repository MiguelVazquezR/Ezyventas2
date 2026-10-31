<?php

namespace App\Services\CashRegisters;

use App\Enums\PaymentMethod;
use App\Enums\SessionCashMovementType;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Services\BankAccounts\BankAccountQueryService;

/**
 * Read model of the cash register: the session the user is working in, the
 * sessions they could join, the free terminals and the bank accounts to
 * declare when opening the register.
 *
 * Shared by the login/me payload and the mobile "current session" endpoint so
 * the app and the web POS always show the same shift.
 */
class CashRegisterSessionQueryService
{
    public function __construct(private readonly BankAccountQueryService $bankAccounts) {}

    /**
     * Everything the POS opening screen needs.
     *
     * @return array<string, mixed>
     */
    public function currentPayload(User $user): array
    {
        return [
            'active_session' => $this->activeSession($user),
            'joinable_sessions' => $this->joinableSessions($user),
            'available_cash_registers' => $this->availableCashRegisters($user),
            'bank_accounts' => $this->bankAccounts->payloadCollection($this->bankAccounts->forUser($user)),
        ];
    }

    /**
     * Session the user is currently working in, or null.
     *
     * @return array<string, mixed>|null
     */
    public function activeSession(User $user): ?array
    {
        $session = $user->getActiveCashRegisterSession();

        return $session ? $this->sessionPayload($session) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionPayload(CashRegisterSession $session): array
    {
        $session->loadMissing('cashRegister:id,name', 'opener:id,name', 'users:id,name');

        return [
            'id' => $session->id,
            'status' => $session->status instanceof \BackedEnum ? $session->status->value : $session->status,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'opening_cash_balance' => (float) $session->opening_cash_balance,
            // Snapshot taken when the register was opened: the app shows it on
            // the closing screen and the web uses it to reconcile accounts.
            'opening_bank_balances' => $session->opening_bank_balances ?? [],
            'cash_register' => $session->cashRegister ? [
                'id' => $session->cashRegister->id,
                'name' => $session->cashRegister->name,
            ] : null,
            'opener' => $session->opener ? [
                'id' => $session->opener->id,
                'name' => $session->opener->name,
            ] : null,
            'users' => $session->users->map(fn ($participant) => [
                'id' => $participant->id,
                'name' => $participant->name,
            ])->values()->all(),
            'totals' => $this->totals($session),
        ];
    }

    /**
     * Money collected during the shift, grouped by payment method.
     *
     * @return array{cash: float, card: float, transfer: float, balance: float}
     */
    public function totals(CashRegisterSession $session): array
    {
        $paymentTotals = $session->getCompletedPaymentTotals();

        return [
            'cash' => (float) ($paymentTotals[PaymentMethod::CASH->value] ?? 0),
            'card' => (float) ($paymentTotals[PaymentMethod::CARD->value] ?? 0),
            'transfer' => (float) ($paymentTotals[PaymentMethod::TRANSFER->value] ?? 0),
            'balance' => (float) ($paymentTotals[PaymentMethod::BALANCE->value] ?? 0),
        ];
    }

    /**
     * Open sessions of the branch that the user could join.
     *
     * @return array<int, array<string, mixed>>
     */
    public function joinableSessions(User $user): array
    {
        return $user->getJoinableCashRegisterSessions()
            ->map(fn ($session) => [
                'id' => $session->id,
                'cash_register' => $session->cashRegister ? [
                    'id' => $session->cashRegister->id,
                    'name' => $session->cashRegister->name,
                ] : null,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'opener' => $session->opener ? [
                    'id' => $session->opener->id,
                    'name' => $session->opener->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Active terminals of the branch that are not in use yet.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function availableCashRegisters(User $user): array
    {
        return $user->getAvailableCashRegisters()
            ->map(fn ($register) => [
                'id' => $register->id,
                'name' => $register->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the closing screen needs (the cut), both before and after
     * closing the register.
     *
     * Formulas mirror the web closing modal and
     * CashRegisterSession::closeSession(), so the phone shows the same numbers
     * the web would:
     * expected_total = opening + cash sales + inflows - outflows
     * difference     = counted - expected (negative: missing money)
     *
     * @return array<string, mixed>
     */
    public function summaryPayload(CashRegisterSession $session, User $user): array
    {
        $isOwner = !$user->roles()->exists();
        $paymentTotals = $session->getCompletedPaymentTotals();

        $cashSales = (float) ($paymentTotals[PaymentMethod::CASH->value] ?? 0);
        $inflows = (float) $session->cashMovements()
            ->where('type', SessionCashMovementType::INFLOW->value)
            ->sum('amount');
        $outflows = (float) $session->cashMovements()
            ->where('type', SessionCashMovementType::OUTFLOW->value)
            ->sum('amount');

        $expectedTotal = (float) $session->opening_cash_balance + $cashSales + $inflows - $outflows;
        $countedTotal = $session->closing_cash_balance !== null ? (float) $session->closing_cash_balance : null;

        return [
            'session' => array_merge($this->sessionPayload($session), [
                'closed_at' => $session->closed_at?->toIso8601String(),
                'closing_cash_balance' => $countedTotal,
                'calculated_cash_total' => $session->calculated_cash_total !== null
                    ? (float) $session->calculated_cash_total
                    : null,
                'cash_difference' => $session->cash_difference !== null
                    ? (float) $session->cash_difference
                    : null,
                'notes' => $session->notes,
            ]),
            'cash' => [
                'opening' => (float) $session->opening_cash_balance,
                'cash_sales' => round($cashSales, 2),
                'inflows' => round($inflows, 2),
                'outflows' => round($outflows, 2),
                'expected_total' => round($expectedTotal, 2),
                'counted_total' => $countedTotal,
                'difference' => $countedTotal !== null ? round($countedTotal - $expectedTotal, 2) : null,
            ],
            'payments_by_method' => [
                PaymentMethod::CASH->value => round($cashSales, 2),
                PaymentMethod::CARD->value => (float) ($paymentTotals[PaymentMethod::CARD->value] ?? 0),
                PaymentMethod::TRANSFER->value => (float) ($paymentTotals[PaymentMethod::TRANSFER->value] ?? 0),
                PaymentMethod::BALANCE->value => (float) ($paymentTotals[PaymentMethod::BALANCE->value] ?? 0),
            ],
            'cash_movements' => $this->cashMovementPayload($session),
            'bank_accounts' => $session->calculateBankAccountSummary($user, $isOwner),
            'counts' => [
                'transactions' => $session->transactions()->count(),
                'payments' => $session->payments()->count(),
            ],
        ];
    }

    /**
     * Cash movements of the shift (inflows and outflows of the drawer).
     *
     * @return array<int, array<string, mixed>>
     */
    private function cashMovementPayload(CashRegisterSession $session): array
    {
        return $session->cashMovements()
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($movement) => [
                'id' => $movement->id,
                'type' => $movement->type instanceof \BackedEnum ? $movement->type->value : $movement->type,
                'amount' => (float) $movement->amount,
                'description' => $movement->description,
                'user' => $movement->user ? [
                    'id' => $movement->user->id,
                    'name' => $movement->user->name,
                ] : null,
                'created_at' => $movement->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
