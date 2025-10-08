<?php

namespace App\Exports;

use App\Enums\TransactionChannel;
use App\Exports\Sheets\ExpensesSheet;
use App\Exports\Sheets\PaymentsSheet;
use App\Exports\Sheets\SalesSheet;
use App\Exports\Sheets\SummarySheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;

class FinancialReportExport implements WithMultipleSheets
{
    protected $branchId;
    protected $startDate;
    protected $endDate;

    public function __construct(int $branchId, $startDate, $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function sheets(): array
    {
        $sheets = [];

        // 1. Hoja de Resumen General
        $sheets[] = new SummarySheet($this->branchId, $this->startDate, $this->endDate);
        
        // 2. Nueva Hoja de Pagos Recibidos
        $sheets[] = new PaymentsSheet($this->branchId, $this->startDate, $this->endDate);

        // 3. Hojas de Ventas por Canal (dinámicamente)
        $channelsInPeriod = Transaction::where('branch_id', $this->branchId)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->select('channel')
            ->distinct()
            ->pluck('channel');

        foreach ($channelsInPeriod as $channel) {
            $sheets[] = new SalesSheet($this->branchId, $this->startDate, $this->endDate, $channel);
        }

        // 4. Hoja de Gastos
        $sheets[] = new ExpensesSheet($this->branchId, $this->startDate, $this->endDate);

        return $sheets;
    }
}