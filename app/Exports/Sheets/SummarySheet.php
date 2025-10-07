<?php

namespace App\Exports\Sheets;

use App\Enums\PaymentMethod;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SummarySheet implements FromArray, WithTitle, WithHeadings, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    private $branchId;
    private $startDate;
    private $endDate;
    private $data;

    public function __construct(int $branchId, $startDate, $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->data = $this->prepareData();
    }

    public function array(): array
    {
        return $this->data;
    }

    private function prepareData(): array
    {
        $payments = Payment::where('status', 'completado')
            ->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId))
            ->whereBetween('payment_date', [$this->startDate, $this->endDate])
            ->get();

        $expenses = Expense::where('branch_id', $this->branchId)
            ->where('status', 'pagado')
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->get();

        $incomeByMethod = $this->calculateByMethod($payments);
        $expenseByMethod = $this->calculateByMethod($expenses);

        $totalIncome = $incomeByMethod->sum();
        $totalExpense = $expenseByMethod->sum();

        return [
            ['Ingresos', $incomeByMethod['efectivo'], $incomeByMethod['tarjeta'], $incomeByMethod['transferencia'], $totalIncome],
            ['Gastos', $expenseByMethod['efectivo'], $expenseByMethod['tarjeta'], $expenseByMethod['transferencia'], $totalExpense],
            ['Balance (Utilidad)', $incomeByMethod['efectivo'] - $expenseByMethod['efectivo'], $incomeByMethod['tarjeta'] - $expenseByMethod['tarjeta'], $incomeByMethod['transferencia'] - $expenseByMethod['transferencia'], $totalIncome - $totalExpense],
        ];
    }

    private function calculateByMethod(Collection $collection): Collection
    {
        $methods = ['efectivo', 'tarjeta', 'transferencia'];
        $result = collect(array_fill_keys($methods, 0));

        foreach ($collection as $item) {
            $methodValue = $item->payment_method->value;
            if (in_array($methodValue, $methods)) {
                $result[$methodValue] += $item->amount;
            }
        }
        return $result;
    }

    public function title(): string
    {
        return 'Resumen General';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);
        $sheet->getStyle('E2:E4')->getFont()->setBold(true);
    }

    public function headings(): array
    {
        return ['Concepto', 'Efectivo', 'Tarjeta', 'Transferencia', 'Total'];
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }
}