<?php

namespace App\Exports\Sheets;

use App\Models\Expense;
use App\Models\Payment;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SummarySheet implements FromArray, WithTitle, ShouldAutoSize, WithStyles, WithColumnFormatting
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

    public function title(): string
    {
        return 'Resumen General';
    }

    public function array(): array
    {
        // Obtener totales de pagos agrupados por método
        $payments = Payment::query()
            ->where('status', 'completado')
            ->whereBetween('payment_date', [$this->startDate, $this->endDate])
            ->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId))
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // Obtener totales de gastos agrupados por método
        $expenses = Expense::query()
            ->where('branch_id', $this->branchId)
            ->where('status', 'pagado')
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $totalPayments = $payments->sum();
        $totalExpenses = $expenses->sum();
        $balance = $totalPayments - $totalExpenses;

        return [
            ['Reporte de Ingresos y Gastos del ' . $this->startDate->format('d/m/Y') . ' al ' . $this->endDate->format('d/m/Y')],
            [],
            ['Resumen General'],
            ['Concepto', 'Efectivo', 'Tarjeta', 'Transferencia', 'Saldo', 'Total'],
            [
                'Pagos Recibidos',
                $payments->get('efectivo', 0),
                $payments->get('tarjeta', 0),
                $payments->get('transferencia', 0),
                $payments->get('saldo', 0),
                $totalPayments,
            ],
            [
                'Gastos',
                $expenses->get('efectivo', 0),
                $expenses->get('tarjeta', 0),
                $expenses->get('transferencia', 0),
                0, // No hay gastos con 'saldo'
                $totalExpenses,
            ],
            [
                'Balance (Utilidad)',
                ($payments->get('efectivo', 0) - $expenses->get('efectivo', 0)),
                ($payments->get('tarjeta', 0) - $expenses->get('tarjeta', 0)),
                ($payments->get('transferencia', 0) - $expenses->get('transferencia', 0)),
                $payments->get('saldo', 0),
                $balance,
            ],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

        $sheet->getStyle('A4:F4')->getFont()->setBold(true);
        $sheet->getStyle('A4:F4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');

        $sheet->getStyle('A7:F7')->getFont()->setBold(true);
        $sheet->getStyle('A7:F7')->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'F' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }
}