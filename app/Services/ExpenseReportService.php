<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseReportService
{
    public function byCategory(int $branchId, Carbon $start, Carbon $end): array
    {
        return Expense::where('branch_id', $branchId)
            ->whereBetween('expense_date', [$start, $end])
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->select(
                'expense_categories.name as category',
                DB::raw('SUM(expenses.amount) as total'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'total'    => (float) $row->total,
                'count'    => (int) $row->count,
            ])
            ->toArray();
    }

    public function trend(int $branchId, int $months = 6): array
    {
        $start = now()->subMonths($months)->startOfMonth();

        $monthly = Expense::where('branch_id', $branchId)
            ->where('expense_date', '>=', $start)
            ->select(
                DB::raw("DATE_FORMAT(expense_date, '%Y-%m') as month"),
                DB::raw('SUM(amount) as total'),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $trend = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $trend[] = [
                'month' => $month,
                'total' => (float) ($monthly->get($month)?->total ?? 0),
            ];
        }

        return $trend;
    }
}
