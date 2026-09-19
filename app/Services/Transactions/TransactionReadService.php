<?php

namespace App\Services\Transactions;

use App\Enums\TransactionChannel;
use App\Models\Transaction;
use App\Services\BankAccounts\BankAccountQueryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model of the sales history of a branch.
 *
 * Filters and ordering mirror the web sales list, so the app and the web always
 * show the same results for the same query.
 */
class TransactionReadService
{
    /**
     * Supported sort fields, mapped to real columns to avoid SQL injection.
     */
    private const SORT_COLUMNS = [
        'created_at' => 'transactions.created_at',
        'folio' => 'transactions.folio',
        'customer.name' => 'customers.name',
    ];

    public function __construct(private readonly BankAccountQueryService $bankAccounts) {}

    /**
     * Sales of the branch.
     *
     * Balance payments ("abono_a_saldo") are excluded: they are customer
     * account movements, not sales (same rule as the web list).
     *
     * @param  array<string, mixed>  $filters
     */
    public function queryForBranch(int $branchId, array $filters = []): Builder
    {
        $query = Transaction::query()
            ->leftJoin('customers', 'transactions.customer_id', '=', 'customers.id')
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.branch_id', $branchId)
            ->where('transactions.channel', '!=', TransactionChannel::BALANCE_PAYMENT->value)
            ->with(['customer:id,name', 'user:id,name'])
            ->withCount('items')
            ->select('transactions.*');

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $q) use ($search) {
                $q->where('transactions.folio', 'like', "%{$search}%")
                    ->orWhere('customers.name', 'like', "%{$search}%")
                    ->orWhere('transactions.contact_info->name', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('transactions.status', $filters['status']);
        }

        if (!empty($filters['date_start'])) {
            $query->whereDate('transactions.created_at', '>=', $filters['date_start']);
        }

        if (!empty($filters['date_end'])) {
            $query->whereDate('transactions.created_at', '<=', $filters['date_end']);
        }

        if (!empty($filters['updated_since'])) {
            $query->where('transactions.updated_at', '>=', Carbon::parse($filters['updated_since']));
        }

        return $query->orderBy(
            $this->sortColumn($filters['sort_field'] ?? 'created_at'),
            ($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc'
        );
    }

    /**
     * Finds one sale of the branch with everything the detail screen needs.
     */
    public function findForBranch(int $transactionId, int $branchId): ?Transaction
    {
        return Transaction::query()
            ->where('branch_id', $branchId)
            ->with([
                'branch:id,name',
                'user:id,name',
                'customer:id,name,balance,credit_limit,phone',
                'items',
                'payments.bankAccount',
                'invoice',
                'cashRegisterSession:id,cash_register_id',
            ])
            ->find($transactionId);
    }

    /**
     * Compact payload of the sales list.
     *
     * @return array<string, mixed>
     */
    public function listPayload(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'folio' => $transaction->folio,
            'status' => $transaction->status instanceof \BackedEnum ? $transaction->status->value : $transaction->status,
            'channel' => $transaction->channel instanceof \BackedEnum ? $transaction->channel->value : $transaction->channel,
            'customer' => $transaction->customer ? [
                'id' => $transaction->customer->id,
                'name' => $transaction->customer->name,
            ] : null,
            'user' => $transaction->user ? [
                'id' => $transaction->user->id,
                'name' => $transaction->user->name,
            ] : null,
            'contact_info' => $transaction->contact_info,
            'delivery_date' => $transaction->delivery_date?->toIso8601String(),
            'layaway_expiration_date' => $transaction->layaway_expiration_date?->toDateString(),
            'subtotal' => (string) $transaction->subtotal,
            'total_discount' => (string) $transaction->total_discount,
            'shipping_cost' => (string) $transaction->shipping_cost,
            'total' => (float) $transaction->total,
            'total_paid' => (float) $transaction->total_paid,
            'remaining_due' => (float) $transaction->remaining_due,
            // Number of lines of the sale (not the sum of the quantities).
            'items_count' => (int) ($transaction->items_count ?? $transaction->items->count()),
            'is_order' => $transaction->isOrder(),
            'invoiced' => (bool) $transaction->invoiced,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * Full payload: the list payload plus items, payments and settlement data.
     *
     * @return array<string, mixed>
     */
    public function detailPayload(Transaction $transaction): array
    {
        return array_merge($this->listPayload($transaction), [
            'branch' => $transaction->branch ? [
                'id' => $transaction->branch->id,
                'name' => $transaction->branch->name,
            ] : null,
            'customer' => $transaction->customer ? [
                'id' => $transaction->customer->id,
                'name' => $transaction->customer->name,
                'balance' => (string) $transaction->customer->balance,
                'credit_limit' => (string) $transaction->customer->credit_limit,
            ] : null,
            'notes' => $transaction->notes,
            'shipping_address' => $transaction->shipping_address,
            'total_tax' => (string) $transaction->total_tax,
            'paid_amount' => (float) $transaction->total_paid,
            'pending_balance' => (float) $transaction->remaining_due,
            'is_paid' => $transaction->remaining_due <= 0.01,
            'cash_register_session' => $transaction->cash_register_session_id
                ? ['id' => $transaction->cash_register_session_id]
                : null,
            // Billing is out of the mobile scope for now: only the identity of
            // the invoice is exposed so the app can flag the sale as invoiced.
            'invoice' => $transaction->invoice ? [
                'id' => $transaction->invoice->id,
                'folio' => $transaction->invoice->folio,
                'status' => $transaction->invoice->status instanceof \BackedEnum
                    ? $transaction->invoice->status->value
                    : $transaction->invoice->status,
            ] : null,
            'items' => $transaction->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'itemable_type' => $item->itemable_type,
                'itemable_id' => $item->itemable_id,
                'quantity' => (float) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'discount_amount' => (string) $item->discount_amount,
                'discount_reason' => $item->discount_reason,
                'tax_amount' => (string) $item->tax_amount,
                'line_total' => (string) $item->line_total,
            ])->values()->all(),
            'payments' => $transaction->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (string) $payment->amount,
                'payment_method' => $payment->payment_method instanceof \BackedEnum
                    ? $payment->payment_method->value
                    : $payment->payment_method,
                'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'notes' => $payment->notes,
                'bank_account' => $payment->bankAccount ? $this->bankAccounts->payload($payment->bankAccount) : null,
            ])->values()->all(),
        ]);
    }

    private function sortColumn(string $sortField): string|DB\Expression
    {
        if ($sortField === 'total') {
            return DB::raw('(transactions.subtotal - transactions.total_discount + transactions.total_tax + COALESCE(transactions.shipping_cost, 0))');
        }

        return self::SORT_COLUMNS[$sortField] ?? self::SORT_COLUMNS['created_at'];
    }
}
