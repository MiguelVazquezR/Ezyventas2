<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
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

    /**
     * Aging buckets for certified (issued) invoices grouped by days since issued_at.
     *
     * NOTE: The invoices table has no explicit "paid" flag — payment tracking
     * lives in the POS system (transactions/payments), not on the invoice
     * record. This report buckets "certificada" invoices as the closest proxy
     * for "outstanding collection." If a dedicated payment-status column is
     * added to invoices in the future, update this method accordingly.
     *
     * @param  int     $branchId
     * @param  string  $asOfDate  YYYY-MM-DD, defaults to today
     */
    public function getInvoiceAging(int $branchId, ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : now()->endOfDay();

        $invoices = Invoice::where('branch_id', $branchId)
            ->where('status', InvoiceStatus::CERTIFIED)
            ->whereNotNull('issued_at')
            ->get(['id', 'folio', 'total', 'issued_at', 'customer_id'])
            ->map(function ($inv) use ($asOf) {
                $days = (int) $asOf->diffInDays($inv->issued_at);

                $bucket = match (true) {
                    $days <= 30 => '0-30',
                    $days <= 60 => '31-60',
                    $days <= 90 => '61-90',
                    default    => '90+',
                };

                return [
                    'invoice_id' => $inv->id,
                    'folio'      => $inv->folio,
                    'total'      => (float) $inv->total,
                    'issued_at'  => $inv->issued_at->toDateString(),
                    'days_aging' => $days,
                    'bucket'     => $bucket,
                ];
            });

        $buckets = $invoices->groupBy('bucket')->map(fn ($group) => [
            'count'       => $group->count(),
            'total'       => round($group->sum('total'), 2),
        ]);

        return [
            'as_of_date' => $asOf->toDateString(),
            'buckets'    => [
                '0-30'  => $buckets->get('0-30', ['count' => 0, 'total' => 0]),
                '31-60' => $buckets->get('31-60', ['count' => 0, 'total' => 0]),
                '61-90' => $buckets->get('61-90', ['count' => 0, 'total' => 0]),
                '90+'   => $buckets->get('90+', ['count' => 0, 'total' => 0]),
            ],
            'total_outstanding' => [
                'count' => $invoices->count(),
                'total' => round($invoices->sum('total'), 2),
            ],
            'details' => $invoices->sortByDesc('days_aging')->values()->toArray(),
        ];
    }
}
