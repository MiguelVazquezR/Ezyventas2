<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerBalanceMovement;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerReportService
{
    public function getPurchaseHistory(int $branchId, int $customerId, int $limit = 20): array
    {
        $customer = Customer::where('branch_id', $branchId)->findOrFail($customerId);

        $transactions = $customer->transactions()
            ->with(['items.itemable', 'user:id,name'])
            ->latest()
            ->take($limit)
            ->get();

        return $transactions->map(function ($tx) {
            $items = $tx->items->map(function ($item) {
                $itemableName = 'Desconocido';
                if ($item->itemable) {
                    $itemableName = $item->itemable->name ?? $item->itemable->id;
                }

                return [
                    'description' => $item->description ?? $itemableName,
                    'quantity'    => (int) $item->quantity,
                    'unit_price'  => (float) $item->unit_price,
                    'line_total'  => (float) $item->line_total,
                ];
            });

            return [
                'id'         => $tx->id,
                'folio'      => $tx->folio,
                'status'     => $tx->status->value,
                'total'      => (float) $tx->total,
                'created_at' => $tx->created_at->toDateTimeString(),
                'seller'     => $tx->user?->name,
                'items'      => $items->toArray(),
            ];
        })->toArray();
    }

    public function getAccountStatement(int $branchId, int $customerId): array
    {
        $customer = Customer::where('branch_id', $branchId)->findOrFail($customerId);

        $movements = CustomerBalanceMovement::where('customer_id', $customer->id)
            ->orderBy('created_at')
            ->get();

        $running = (float) ($movements->first()?->balance_after ?? 0) - (float) ($movements->first()?->amount ?? 0);

        return [
            'customer_id'    => $customer->id,
            'customer_name'  => $customer->name,
            'current_balance'=> (float) $customer->balance,
            'credit_limit'   => (float) $customer->credit_limit,
            'movements'      => $movements->map(function ($m) use (&$running) {
                $running += (float) $m->amount;

                return [
                    'date'            => $m->created_at->toDateString(),
                    'type'            => $m->type->value,
                    'amount'          => (float) $m->amount,
                    'running_balance' => (float) $running,
                    'notes'           => $m->notes,
                ];
            })->toArray(),
        ];
    }

    public function getTopCustomers(int $branchId, Carbon $start, Carbon $end, int $limit = 10): array
    {
        return Customer::where('branch_id', $branchId)
            ->whereHas('transactions', function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->whereNotIn('status', ['cancelado', 'cambiado']);
            })
            ->withSum(['transactions' => function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->whereNotIn('status', ['cancelado', 'cambiado']);
            }], DB::raw('subtotal - total_discount + total_tax'))
            ->orderByDesc('transactions_sum_subtotal_total_discount_total_tax')
            ->take($limit)
            ->get(['id', 'name', 'email', 'phone'])
            ->map(function ($c) {
                return [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'email'      => $c->email,
                    'phone'      => $c->phone,
                    'total_spent'=> (float) ($c->transactions_sum_subtotal_total_discount_total_tax ?? 0),
                ];
            })
            ->toArray();
    }
}
