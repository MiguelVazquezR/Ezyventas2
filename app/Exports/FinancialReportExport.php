<?php

namespace App\Exports;

use App\Exports\Sheets\ExpensesSheet;
use App\Exports\Sheets\SalesSheet;
use App\Exports\Sheets\SummarySheet;
use App\Models\Transaction;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FinancialReportExport implements WithMultipleSheets
{
    protected $startDate;
    protected $endDate;
    protected $branchId;

    public function __construct($branchId, $startDate, $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    /**
     * @return array
     */
    public function sheets(): array
    {
        $sheets = [];

        // 1. Añadir la hoja de resumen primero
        $sheets[] = new SummarySheet($this->branchId, $this->startDate, $this->endDate);

        // 2. Obtener los canales de venta que tienen transacciones en el periodo
        $channels = Transaction::where('branch_id', $this->branchId)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->distinct()
            ->pluck('channel');

        // 3. Crear una hoja de ventas para cada canal
        foreach ($channels as $channel) {
            $sheets[] = new SalesSheet($this->branchId, $this->startDate, $this->endDate, $channel);
        }
        
        // 4. Añadir la hoja de gastos al final
        $sheets[] = new ExpensesSheet($this->branchId, $this->startDate, $this->endDate);

        return $sheets;
    }
}