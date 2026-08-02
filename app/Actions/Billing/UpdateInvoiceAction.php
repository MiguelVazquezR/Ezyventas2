<?php

namespace App\Actions\Billing;

use App\Models\Billing\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Billing\SWSapienService;
use Illuminate\Support\Facades\DB;

class UpdateInvoiceAction
{
    public function __construct(
        private readonly SWSapienService $swService,
    ) {}

    /**
     * Update a draft invoice with new data.
     *
     * Only draft invoices can be edited. Once stamped (certified),
     * the invoice is immutable through this action.
     *
     * 1. Validate the invoice is a draft.
     * 2. Update invoice header fields.
     * 3. Delete old items and recreate from submitted data.
     * 4. Recalculate totals.
     * 5. Sync customer fiscal data if changed.
     */
    public function execute(array $data, Invoice $invoice, User $user): Invoice
    {
        if (! $invoice->isEditable()) {
            throw new \RuntimeException('Solo las prefacturas (borradores) pueden editarse.');
        }

        return DB::transaction(function () use ($data, $invoice, $user) {
            // 1. Update header
            $subtotal      = 0;
            $discountTotal = 0;
            $taxesTotal    = 0;
            $retainedTotal = 0;
            $grandTotal    = 0;

            $invoice->update([
                'fiscal_profile_id'   => $data['fiscal_profile_id'] ?? $invoice->fiscal_profile_id,
                'customer_id'         => $data['customer_id'] ?? $invoice->customer_id,
                'series'              => $data['series'] ?? $invoice->series,
                'receiver_rfc'        => $data['receiver_rfc'],
                'receiver_legal_name'  => $data['receiver_legal_name'],
                'receiver_tax_regime'  => $data['receiver_tax_regime'],
                'receiver_postal_code' => $data['receiver_postal_code'],
                'cfdi_use'             => $data['cfdi_use'],
                'exportacion'          => $data['exportacion'] ?? '01',
                'tipo_comprobante'     => $data['tipo_comprobante'] ?? 'I',
                'payment_form'         => $data['payment_form'],
                'payment_method'       => $data['payment_method'],
                'currency'             => $data['currency'] ?? 'MXN',
                'exchange_rate'        => ($data['currency'] ?? 'MXN') !== 'MXN' ? ($data['exchange_rate'] ?? null) : null,
                'subtotal'             => 0,
                'discount_total'       => 0,
                'taxes_total'          => 0,
                'retained_taxes_total' => 0,
                'total'                => 0,
            ]);

            // 2. Remove old items
            $invoice->items()->delete();

            // 3. Recreate items from submitted data
            foreach ($data['items'] as $itemData) {
                $taxRate       = (float) ($itemData['tax_rate'] ?? 0.16);
                $lineDiscount  = (float) ($itemData['discount_amount'] ?? 0);
                $lineSubtotal  = round((float) $itemData['quantity'] * (float) $itemData['unit_price'], 2);
                $baseAfterDisc = $lineSubtotal - $lineDiscount;
                $hasTransfer   = ($itemData['objeto_imp'] ?? '02') === '02';
                $lineTaxAmount = $hasTransfer ? round($baseAfterDisc * $taxRate, 2) : 0;

                $retentions = $itemData['retentions'] ?? [];

                if (empty($retentions) && ! empty($itemData['retained_tax_type'])) {
                    $retentions[] = [
                        'type'   => $itemData['retained_tax_type'],
                        'rate'   => (float) ($itemData['retained_tax_rate'] ?? 0),
                        'amount' => (float) ($itemData['retained_tax_amount'] ?? 0),
                    ];
                }

                $lineRetainedTotal = 0;
                $normalizedRetentions = [];
                foreach ($retentions as $ret) {
                    $retType   = $ret['type'] ?? $ret['retained_tax_type'] ?? null;
                    $retRate   = (float) ($ret['rate'] ?? $ret['retained_tax_rate'] ?? 0);
                    $retAmount = (float) ($ret['amount'] ?? $ret['retained_tax_amount'] ?? 0);

                    if (! $retType || $retAmount <= 0) {
                        continue;
                    }

                    $normalizedRetentions[] = [
                        'type'   => $retType,
                        'rate'   => $retRate,
                        'amount' => $retAmount,
                    ];
                    $lineRetainedTotal += $retAmount;
                }

                $lineTotal = round($baseAfterDisc + $lineTaxAmount - $lineRetainedTotal, 2);

                InvoiceItem::create([
                    'invoice_id'          => $invoice->id,
                    'product_id'          => $itemData['product_id'] ?? null,
                    'description'         => $itemData['description'],
                    'quantity'            => (float) $itemData['quantity'],
                    'sat_unit_code'       => $itemData['sat_unit_code'] ?? 'H87',
                    'unit_name'           => $itemData['unit_name'] ?? null,
                    'unit_price'          => (float) $itemData['unit_price'],
                    'subtotal'            => $lineSubtotal,
                    'discount_amount'     => $lineDiscount,
                    'tax_amount'          => $lineTaxAmount,
                    'total'               => $lineTotal,
                    'sat_product_code'    => $itemData['sat_product_code'] ?? '01010101',
                    'no_identificacion'   => $itemData['no_identificacion'] ?? null,
                    'objeto_imp'          => $itemData['objeto_imp'] ?? '02',
                    'tax_type'            => $hasTransfer ? ($itemData['tax_type'] ?? '002') : null,
                    'tax_rate'            => $hasTransfer ? $taxRate : null,
                    'retained_tax_type'   => ! empty($normalizedRetentions) ? $normalizedRetentions[0]['type'] : null,
                    'retained_tax_rate'   => ! empty($normalizedRetentions) ? $normalizedRetentions[0]['rate'] : null,
                    'retained_tax_amount' => $lineRetainedTotal,
                    'retentions'          => ! empty($normalizedRetentions) ? $normalizedRetentions : null,
                ]);

                $subtotal      += $lineSubtotal;
                $discountTotal += $lineDiscount;
                $taxesTotal    += $lineTaxAmount;
                $retainedTotal += $lineRetainedTotal;
                $grandTotal    += $lineTotal;
            }

            // 4. Update calculated totals
            $invoice->update([
                'subtotal'             => round($subtotal, 2),
                'discount_total'       => round($discountTotal, 2),
                'taxes_total'          => round($taxesTotal, 2),
                'retained_taxes_total' => round($retainedTotal, 2),
                'total'                => round($grandTotal, 2),
            ]);

            // 5. Sync customer fiscal data
            if (! empty($data['customer_id'])) {
                $this->swService->syncCustomerFiscalData($data['customer_id'], $data);
            }

            return $invoice->fresh('items');
        });
    }
}
