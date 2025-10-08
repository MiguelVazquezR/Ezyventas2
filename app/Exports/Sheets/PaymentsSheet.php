<?php

namespace App\Exports\Sheets;

use App\Models\Payment;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PaymentsSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithColumnFormatting
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

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return Payment::query()
            ->where('status', 'completado')
            ->whereBetween('payment_date', [$this->startDate, $this->endDate])
            ->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId))
            ->with(['transaction.customer:id,name', 'transaction:id,folio,created_at']);
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return 'Pagos Recibidos';
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Fecha de Pago',
            'Folio de Venta Asociada',
            'Fecha de Venta',
            'Cliente',
            'Método de Pago',
            'Monto Pagado',
            'Tipo de Pago',
        ];
    }

    /**
     * @param Payment $payment
     * @return array
     */
    public function map($payment): array
    {
        // Determina si el pago es un abono o un pago inicial
        $paymentType = 'Pago de Venta';
        if ($payment->payment_date->format('Y-m-d') > $payment->transaction->created_at->format('Y-m-d')) {
            $paymentType = 'Abono a Venta Anterior';
        }

        return [
            $payment->payment_date->format('Y-m-d H:i'),
            optional($payment->transaction)->folio ?? 'N/A',
            optional($payment->transaction)->created_at->format('Y-m-d H:i') ?? 'N/A',
            optional(optional($payment->transaction)->customer)->name ?? 'Mostrador',
            $payment->payment_method->value,
            $payment->amount,
            $paymentType,
        ];
    }

    /**
     * @return array
     */
    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_CURRENCY_USD_SIMPLE,
        ];
    }

    /**
     * @param Worksheet $sheet
     */
    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDEDED');
    }
}