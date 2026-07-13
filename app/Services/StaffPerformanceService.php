<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StaffPerformanceService
{
    public function salesByEmployee(int $branchId, Carbon $start, Carbon $end): array
    {
        return Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', ['cancelado', 'cambiado'])
            ->join('users', 'transactions.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(subtotal - total_discount + total_tax) as total_sales'),
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn ($row) => [
                'user_id'           => $row->id,
                'name'              => $row->name,
                'transaction_count' => (int) $row->transaction_count,
                'total_sales'       => (float) $row->total_sales,
            ])
            ->toArray();
    }

    public function rankingByBranch(int $subscriptionId, Carbon $start, Carbon $end): array
    {
        return Transaction::whereHas('branch', fn ($q) => $q->where('subscription_id', $subscriptionId))
            ->whereBetween('created_at', [$start, $end])
            ->whereNotIn('status', ['cancelado', 'cambiado'])
            ->join('branches', 'transactions.branch_id', '=', 'branches.id')
            ->select(
                'branches.id as branch_id',
                'branches.name as branch_name',
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(subtotal - total_discount + total_tax) as total_sales'),
            )
            ->groupBy('branches.id', 'branches.name')
            ->orderByDesc('total_sales')
            ->get()
            ->map(fn ($row) => [
                'branch_id'         => $row->branch_id,
                'branch_name'       => $row->branch_name,
                'transaction_count' => (int) $row->transaction_count,
                'total_sales'       => (float) $row->total_sales,
            ])
            ->toArray();
    }
}
