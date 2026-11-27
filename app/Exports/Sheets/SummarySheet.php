<?php

namespace App\Exports\Sheets;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
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
        return 'Resumen general';
    }

    public function array(): array
    {
        $paymentTotals = $this->paymentTotals();
        $internalTotals = $this->expenseTotals(false);
        $externalTotals = $this->expenseTotals(true);

        $totalPayments = array_sum($paymentTotals);
        $totalInternalExpenses = array_sum($internalTotals);
        $totalExternalExpenses = array_sum($externalTotals);
        $totalExpenses = $totalInternalExpenses + $totalExternalExpenses;
        $totalExpensesByMethod = $this->mergeTotals($internalTotals, $externalTotals);
        $totalSales = $this->totalSales();

        return [
            ['Reporte de ingresos y gastos del ' . $this->startDate->format('d/m/Y') . ' al ' . $this->endDate->format('d/m/Y')],
            [],
            ['Resumen General'],
            ['Concepto', 'Efectivo', 'Tarjeta', 'Transferencia', 'Saldo', 'Intercambio', 'Total'],
            $this->row('Ventas totales', [], $totalSales),
            $this->row('Pagos recibidos', $paymentTotals, $totalPayments),
            $this->row('Gastos del negocio', $internalTotals, $totalInternalExpenses),
            $this->row('Gastos externos', $externalTotals, $totalExternalExpenses),
            $this->row('Gastos totales', $totalExpensesByMethod, $totalExpenses),
            [],
            $this->row('Ganancia del negocio', [], $totalSales - $totalInternalExpenses),
            $this->row('Ganancia total (incluye gastos externos)', [], $totalSales - $totalExpenses),
            $this->row('Flujo del negocio (pagos - gastos del negocio)', [], $totalPayments - $totalInternalExpenses),
            $this->row('Flujo total (pagos - todos los gastos)', [], $totalPayments - $totalExpenses),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);

        $sheet->getStyle('A4:G4')->getFont()->setBold(true);
        $sheet->getStyle('A4:G4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');

        $sheet->getStyle('A9:G9')->getFont()->setBold(true);
        $sheet->getStyle('A11:G14')->getFont()->setBold(true);
        $sheet->getStyle('A11:G11')->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }

    public function columnFormats(): array
    {
        return [
            'B' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'C' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'D' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'F' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'G' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    private function paymentTotals(): array
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::COMPLETED)
            ->whereBetween('payment_date', [$this->startDate, $this->endDate])
            ->whereHas('transaction', fn ($query) => $query
                ->where('branch_id', $this->branchId)
                ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED, TransactionStatus::CHANGED]))
            ->get(['payment_method', 'amount']);

        return $this->totalsByMethod($payments);
    }

    private function expenseTotals(bool $isExternal): array
    {
        $expenses = Expense::query()
            ->where('branch_id', $this->branchId)
            ->where('status', ExpenseStatus::PAID)
            ->where('is_external', $isExternal)
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->get(['payment_method', 'amount']);

        return $this->totalsByMethod($expenses);
    }

    private function totalSales(): float
    {
        $sales = Transaction::query()
            ->where('branch_id', $this->branchId)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED, TransactionStatus::CHANGED])
            ->selectRaw('SUM(subtotal) as total_subtotal, SUM(total_discount) as total_discount, SUM(total_tax) as total_tax')
            ->first();

        return (float) (($sales->total_subtotal ?? 0) - ($sales->total_discount ?? 0) + ($sales->total_tax ?? 0));
    }

    private function totalsByMethod($rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $method = $row->payment_method?->value ?? 'otro';
            $totals[$method] = ($totals[$method] ?? 0) + (float) $row->amount;
        }

        return $totals;
    }

    private function mergeTotals(array $first, array $second): array
    {
        $merged = $first;

        foreach ($second as $method => $total) {
            $merged[$method] = ($merged[$method] ?? 0) + $total;
        }

        return $merged;
    }

    private function row(string $label, array $totals, float $total): array
    {
        return [
            $label,
            $totals['efectivo'] ?? null,
            $totals['tarjeta'] ?? null,
            $totals['transferencia'] ?? null,
            $totals['saldo'] ?? null,
            $totals['intercambio'] ?? null,
            $total,
        ];
    }
}