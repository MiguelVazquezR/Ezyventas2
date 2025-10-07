<?php

namespace App\Exports\Sheets;

use App\Models\Expense;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ExpensesSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    private $branchId;
    private $startDate;
    private $endDate;

    public function __construct(int $branchId, $startDate, $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function query()
    {
        return Expense::query()
            ->where('branch_id', $this->branchId)
            ->where('status', 'pagado')
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->with(['category', 'user']);
    }

    public function title(): string
    {
        return 'Detalle de Gastos';
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');
    }

    public function headings(): array
    {
        return ['Fecha', 'Categoría', 'Descripción', 'Monto', 'Método de Pago', 'Registrado por'];
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    public function map($expense): array
    {
        return [
            $expense->expense_date->format('Y-m-d'),
            optional($expense->category)->name ?? 'Sin Categoría',
            $expense->description,
            $expense->amount,
            $expense->payment_method->value,
            optional($expense->user)->name ?? 'N/A',
        ];
    }
}