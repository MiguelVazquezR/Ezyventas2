<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\Carbon;

class TransactionQueryService
{
    public function search(int $branchId, array $filters): array
    {
        $query = Transaction::query()
            ->where('branch_id', $branchId)
            ->with(['customer:id,name', 'user:id,name']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->whereHas('payments', function ($q) use ($filters) {
                $q->where('payment_method', $filters['payment_method']);
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to']));
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        $limit = min((int) ($filters['limit'] ?? 10), 20);

        return $query->latest()
            ->take($limit)
            ->get(['id', 'folio', 'customer_id', 'user_id', 'status', 'channel', 'total', 'created_at'])
            ->toArray();
    }
}
