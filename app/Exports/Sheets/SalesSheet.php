<?php

namespace App\Exports\Sheets;

use App\Models\Transaction;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SalesSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting
{
    private $branchId;
    private $startDate;
    private $endDate;
    private $channel;

    public function __construct(int $branchId, $startDate, $endDate, $channel)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->channel = $channel;
    }

    public function query()
    {
        return Transaction::query()
            ->where('branch_id', $this->branchId)
            ->where('channel', $this->channel)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->with(['customer', 'user', 'items', 'payments']);
    }

    public function title(): string
    {
        return 'Ventas ' . ucfirst(str_replace('_', ' ', $this->channel->value));
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->getStyle('A1:M1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');
    }

    public function headings(): array
    {
        return [
            'Folio', 'Fecha', 'Cliente', 'Vendedor', 'Subtotal', 'Descuento',
            'Impuestos', 'Total Venta', 'Total Pagado', 'Pendiente',
            'Métodos de Pago', 'Conceptos de la Venta', 'Estado',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'F' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'G' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'H' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'I' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
            'J' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    public function map($transaction): array
    {
        $totalPaid = $transaction->payments->sum('amount');
        $pendingAmount = $transaction->total - $totalPaid;
        $paymentMethods = $transaction->payments->pluck('payment_method.value')->unique()->implode(', ');
        $items = $transaction->items->map(function ($item) {
            return "{$item->quantity} x {$item->description} (@ " . number_format($item->unit_price, 2) . ")";
        })->implode("\n");

        return [
            $transaction->folio,
            $transaction->created_at->format('Y-m-d H:i:s'),
            optional($transaction->customer)->name ?? 'N/A',
            optional($transaction->user)->name ?? 'N/A',
            $transaction->subtotal,
            $transaction->total_discount,
            $transaction->total_tax,
            $transaction->total,
            $totalPaid,
            $pendingAmount,
            $paymentMethods,
            $items,
            $transaction->status->value,
        ];
    }
}