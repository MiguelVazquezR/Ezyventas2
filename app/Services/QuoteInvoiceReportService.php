<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class QuoteInvoiceReportService
{
    public function getQuoteStatusSummary(int $branchId, Carbon $start, Carbon $end): array
    {
        return Quote::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'count'  => (int) $row->count,
                'total'  => (float) $row->total,
            ])
            ->toArray();
    }

    public function getConversionRate(int $branchId, Carbon $start, Carbon $end): array
    {
        $totalQuotes = Quote::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $convertedQuotes = Quote::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->where('status', 'venta_generada')
            ->whereNotNull('transaction_id')
            ->count();

        return [
            'total_quotes'    => $totalQuotes,
            'converted_quotes'=> $convertedQuotes,
            'conversion_rate' => $totalQuotes > 0
                ? round(($convertedQuotes / $totalQuotes) * 100, 2)
                : 0,
        ];
    }

    public function getInvoiceStatusSummary(int $branchId, Carbon $start, Carbon $end): array
    {
        return Invoice::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'count'  => (int) $row->count,
                'total'  => (float) $row->total,
            ])
            ->toArray();
    }
}
