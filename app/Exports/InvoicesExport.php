<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Invoices of the selected dashboard period (Fase 3, T308).
 */
class InvoicesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(
        private readonly Collection $invoices,
    ) {}

    public function collection(): Collection
    {
        return $this->invoices;
    }

    public function headings(): array
    {
        return [
            'Folio',
            'Serie',
            'UUID',
            'Estado',
            'Tipo',
            'Método de pago',
            'Receptor',
            'RFC receptor',
            'Total',
            'Fecha de registro',
            'Fecha de emisión',
        ];
    }

    public function map($invoice): array
    {
        return [
            $invoice->folio,
            $invoice->series,
            $invoice->uuid,
            $this->statusLabel($invoice),
            $invoice->tipo_comprobante,
            $invoice->payment_method,
            $invoice->receiver_legal_name,
            $invoice->receiver_rfc,
            (float) $invoice->total,
            optional($invoice->created_at)->format('Y-m-d H:i'),
            optional($invoice->issued_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * Spanish label for the invoice status (client-facing export).
     */
    private function statusLabel($invoice): string
    {
        $value = $invoice->status instanceof \App\Enums\InvoiceStatus
            ? $invoice->status->value
            : (string) $invoice->status;

        return match ($value) {
            'borrador'              => 'Pre-factura',
            'pendiente'             => 'Pendiente',
            'certificada'           => 'Timbrada',
            'en_verificacion'       => 'En verificación',
            'cancelacion_pendiente' => 'Cancelación pendiente',
            'cancelada'             => 'Cancelada',
            'no_solicitada'         => 'No solicitada',
            'solicitada'            => 'Solicitada',
            'generada'              => 'Generada',
            default                 => $value,
        };
    }
}
