<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model of the customers of a branch: search, list payload and the
 * profile shown in the app (active layaways and balance movements).
 */
class CustomerReadService
{
    /**
     * Customers of the branch, optionally filtered by name, company, email or phone.
     */
    public function queryForBranch(int $branchId, ?string $search = null): Builder
    {
        $query = Customer::query()->where('branch_id', $branchId);

        if ($search !== null && trim($search) !== '') {
            $search = trim($search);

            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('name');
    }

    /**
     * Finds a customer of the branch, or null when it belongs to another one.
     */
    public function findForBranch(int $customerId, int $branchId): ?Customer
    {
        return $this->queryForBranch($branchId)->find($customerId);
    }

    /**
     * Compact payload for lists and selectors.
     *
     * @return array<string, mixed>
     */
    public function payload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'company_name' => $customer->company_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'balance' => (string) $customer->balance,
            'credit_limit' => (string) $customer->credit_limit,
            'available_credit' => (float) $customer->available_credit,
        ];
    }

    /**
     * Full profile: contact data, active layaways and the last 50 balance movements.
     *
     * @return array<string, mixed>
     */
    public function detailPayload(Customer $customer): array
    {
        return array_merge($this->payload($customer), [
            'tax_id' => $customer->tax_id,
            'tax_regime' => $customer->tax_regime,
            'address' => $customer->address,
            'fiscal_address' => $customer->fiscal_address,
            'layaway_transactions' => $this->activeLayaways($customer),
            'balance_movements' => $this->balanceMovements($customer),
        ]);
    }

    /**
     * Layaways (apartados) still pending payment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeLayaways(Customer $customer): array
    {
        return $customer->layawayTransactions()
            ->with(['items.itemable', 'payments'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'folio' => $transaction->folio,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'expires_at' => $transaction->layaway_expiration_date?->toDateString(),
                'total' => (string) $transaction->total,
                'total_paid' => (string) $transaction->total_paid,
                'pending_amount' => (string) $transaction->remaining_due,
                'items_count' => (float) $transaction->items->sum('quantity'),
                'items' => $transaction->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->itemable?->name ?? $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Last 50 balance movements of the customer, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function balanceMovements(Customer $customer): array
    {
        return $customer->balanceMovements()
            ->with('transaction:id,folio')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($movement) => [
                'date' => $movement->created_at?->toIso8601String(),
                'type' => $movement->type instanceof \BackedEnum ? $movement->type->value : $movement->type,
                'description' => $movement->notes ?? 'Abono a venta #' . $movement->transaction?->folio,
                'amount' => (string) $movement->amount,
                'resulting_balance' => (string) $movement->balance_after,
                'transaction_id' => $movement->transaction_id,
            ])
            ->values()
            ->all();
    }
}
