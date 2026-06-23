<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\InvoiceItem;

class NovaBillingService
{
    /**
     * Persist a new CFDI invoice and its line items.
     */
    public function createInvoice(array $data, int $branchId): Invoice
    {
        // Build totals from items
        $subtotal = 0;
        $discountTotal = 0;
        $taxesTotal = 0;
        $grandTotal = 0;

        $invoice = Invoice::create([
            'branch_id'           => $branchId,
            'customer_id'         => $data['customer_id'] ?? null,
            'series'              => $data['series'] ?? null,
            'folio'               => $this->generateFolio($branchId),
            'status'              => InvoiceStatus::DRAFT,
            'receiver_rfc'        => $data['receiver_rfc'],
            'receiver_legal_name'  => $data['receiver_legal_name'],
            'receiver_tax_regime'  => $data['receiver_tax_regime'],
            'receiver_postal_code' => $data['receiver_postal_code'],
            'cfdi_use'             => $data['cfdi_use'],
            'payment_form'         => $data['payment_form'],
            'payment_method'       => $data['payment_method'],
            'currency'             => $data['currency'] ?? 'MXN',
            'subtotal'             => 0,  // will update after items
            'discount_total'       => 0,
            'taxes_total'          => 0,
            'total'                => 0,
        ]);

        foreach ($data['items'] as $itemData) {
            $taxRate = $itemData['tax_rate'] ?? 0.16;
            $lineDiscount = $itemData['discount_amount'] ?? 0;
            $lineSubtotal = round($itemData['quantity'] * $itemData['unit_price'], 2);
            $lineSubtotalAfterDiscount = $lineSubtotal - $lineDiscount;
            $lineTaxAmount = round($lineSubtotalAfterDiscount * $taxRate, 2);
            $lineTotal = round($lineSubtotalAfterDiscount + $lineTaxAmount, 2);

            InvoiceItem::create([
                'invoice_id'        => $invoice->id,
                'product_id'        => $itemData['product_id'] ?? null,
                'description'       => $itemData['description'],
                'quantity'          => $itemData['quantity'],
                'sat_unit_code'     => $itemData['sat_unit_code'],
                'unit_price'        => $itemData['unit_price'],
                'subtotal'          => $lineSubtotal,
                'discount_amount'   => $lineDiscount,
                'tax_amount'        => $lineTaxAmount,
                'total'             => $lineTotal,
                'sat_product_code'  => $itemData['sat_product_code'],
                'tax_type'          => $itemData['tax_type'] ?? '002',
                'tax_rate'          => $taxRate,
            ]);

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxesTotal += $lineTaxAmount;
            $grandTotal += $lineTotal;
        }

        // Update invoice-level totals
        $invoice->update([
            'subtotal'       => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'taxes_total'    => round($taxesTotal, 2),
            'total'          => round($grandTotal, 2),
        ]);

        return $invoice;
    }

    /**
     * Map the invoice model to the NovaCFDI JSON payload structure.
     *
     * This payload is ready to be sent once the external API key is configured.
     *
     * @return array<string, mixed>
     */
    public function prepareNovaPayload(Invoice $invoice): array
    {
        $invoice->load('items');
        $billingSetting = BillingSetting::where('branch_id', $invoice->branch_id)->first();

        $conceptos = $invoice->items->map(function (InvoiceItem $item) {
            $concepto = [
                'ClaveProdServ'  => $item->sat_product_code,
                'ClaveUnidad'    => $item->sat_unit_code,
                'Cantidad'       => (float) $item->quantity,
                'Descripcion'    => $item->description,
                'ValorUnitario'  => (float) $item->unit_price,
                'Importe'        => (float) $item->subtotal,
                'Descuento'      => (float) $item->discount_amount,
            ];

            // Attach transferred taxes if applicable
            if ($item->tax_amount > 0) {
                $concepto['Impuestos'] = [
                    'Traslados' => [
                        [
                            'Base'       => round((float) $item->subtotal - (float) $item->discount_amount, 2),
                            'Impuesto'   => $item->tax_type ?? '002',
                            'TipoFactor' => 'Tasa',
                            'TasaOCuota' => (float) $item->tax_rate,
                            'Importe'    => (float) $item->tax_amount,
                        ],
                    ],
                ];
            }

            return $concepto;
        })->values()->toArray();

        $payload = [
            'Receptor' => [
                'Rfc'                     => $invoice->receiver_rfc,
                'Nombre'                  => $invoice->receiver_legal_name,
                'RegimenFiscal'           => $invoice->receiver_tax_regime,
                'DomicilioFiscalReceptor' => $invoice->receiver_postal_code,
                'UsoCFDI'                 => $invoice->cfdi_use,
            ],
            'Conceptos'  => $conceptos,
            'FormaPago'  => $invoice->payment_form,
            'MetodoPago' => $invoice->payment_method,
            'Moneda'     => $invoice->currency,
            'SubTotal'   => (float) $invoice->subtotal,
            'Descuento'  => (float) $invoice->discount_total,
            'Total'      => (float) $invoice->total,
        ];

        // Attach emitter data if billing settings exist
        if ($billingSetting) {
            $payload['Emisor'] = [
                'Rfc'            => $billingSetting->emitter_rfc,
                'Nombre'         => $billingSetting->emitter_legal_name,
                'RegimenFiscal'  => $billingSetting->emitter_tax_regime,
                'CodigoPostal'   => $billingSetting->emitter_postal_code,
            ];
        }

        return $payload;
    }

    /**
     * Generate the next consecutive folio for a branch (default series).
     */
    private function generateFolio(int $branchId): string
    {
        $lastInvoice = Invoice::where('branch_id', $branchId)
            ->orderByDesc('id')
            ->first();

        $nextNumber = $lastInvoice
            ? ((int) $lastInvoice->folio) + 1
            : 1;

        return (string) $nextNumber;
    }
}
