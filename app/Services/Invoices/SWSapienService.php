<?php

namespace App\Services\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoices\FiscalProfile;
use App\Models\Invoices\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SWSapienService
{
    /**
     * Persist a new invoice and its line items in the database.
     */
    public function createInvoice(array $data, int $branchId): Invoice
    {
        $subtotal = 0;
        $discountTotal = 0;
        $taxesTotal = 0;
        $grandTotal = 0;

        $invoice = Invoice::create([
            'branch_id'          => $branchId,
            'fiscal_profile_id'  => $data['fiscal_profile_id'] ?? null,
            'customer_id'        => $data['customer_id'] ?? null,
            'series'               => $data['series'] ?? null,
            'folio'                => $this->generateFolio($branchId),
            'status'               => InvoiceStatus::DRAFT,
            'receiver_rfc'         => $data['receiver_rfc'],
            'receiver_legal_name'   => $data['receiver_legal_name'],
            'receiver_tax_regime'   => $data['receiver_tax_regime'],
            'receiver_postal_code'  => $data['receiver_postal_code'],
            'cfdi_use'              => $data['cfdi_use'],
            'payment_form'          => $data['payment_form'],
            'payment_method'        => $data['payment_method'],
            'currency'              => $data['currency'] ?? 'MXN',
            'subtotal'              => 0,
            'discount_total'        => 0,
            'taxes_total'           => 0,
            'total'                 => 0,
        ]);

        foreach ($data['items'] as $itemData) {
            $taxRate = $itemData['tax_rate'] ?? 0.16;
            $lineDiscount = $itemData['discount_amount'] ?? 0;
            $lineSubtotal = round($itemData['quantity'] * $itemData['unit_price'], 2);
            $lineSubtotalAfterDiscount = $lineSubtotal - $lineDiscount;
            $lineTaxAmount = round($lineSubtotalAfterDiscount * $taxRate, 2);
            $lineTotal = round($lineSubtotalAfterDiscount + $lineTaxAmount, 2);

            InvoiceItem::create([
                'invoice_id'       => $invoice->id,
                'product_id'       => $itemData['product_id'] ?? null,
                'description'      => $itemData['description'],
                'quantity'         => $itemData['quantity'],
                'sat_unit_code'    => $itemData['sat_unit_code'],
                'unit_price'       => $itemData['unit_price'],
                'subtotal'         => $lineSubtotal,
                'discount_amount'  => $lineDiscount,
                'tax_amount'       => $lineTaxAmount,
                'total'            => $lineTotal,
                'sat_product_code' => $itemData['sat_product_code'],
                'tax_type'         => $itemData['tax_type'] ?? '002',
                'tax_rate'         => $taxRate,
            ]);

            $subtotal += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxesTotal += $lineTaxAmount;
            $grandTotal += $lineTotal;
        }

        $invoice->update([
            'subtotal'       => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'taxes_total'    => round($taxesTotal, 2),
            'total'          => round($grandTotal, 2),
        ]);

        return $invoice;
    }

    /**
     * Build the SW Sapien CFDI 4.0 JSON payload.
     *
     * Emitter data (RFC, razon social, regimen fiscal, lugar de expedicion)
     * is sourced from the invoice's FiscalProfile — enabling multi-RFC
     * billing from a single subscription.
     *
     * "Sello", "Certificado" and "NoCertificado" are left empty —
     * SW Sapien stamps them automatically.
     */
    public function buildPayload(Invoice $invoice): array
    {
        $invoice->load(['items', 'fiscalProfile']);

        if (! $invoice->fiscalProfile) {
            throw new \RuntimeException(
                'La factura no tiene un perfil fiscal asociado. Asigna un RFC emisor antes de timbrar.'
            );
        }

        $conceptos = $invoice->items->map(function (InvoiceItem $item) {
            $concepto = [
                'ClaveProdServ' => $item->sat_product_code,
                'ClaveUnidad'   => $item->sat_unit_code,
                'Cantidad'      => (float) $item->quantity,
                'Descripcion'   => $item->description,
                'ValorUnitario' => (float) $item->unit_price,
                'Importe'       => (float) $item->subtotal,
                'Descuento'     => (float) $item->discount_amount,
                'ObjetoImp'     => ($item->tax_amount > 0) ? '02' : '01',
            ];

            if ($item->tax_amount > 0) {
                $concepto['Impuestos'] = [
                    'Traslados' => [[
                        'Base'       => round((float) $item->subtotal - (float) $item->discount_amount, 2),
                        'Impuesto'   => $item->tax_type ?? '002',
                        'TipoFactor' => 'Tasa',
                        'TasaOCuota' => (float) $item->tax_rate,
                        'Importe'    => (float) $item->tax_amount,
                    ]],
                ];
            }

            return $concepto;
        })->values()->toArray();

        $payload = [
            'TipoDeComprobante' => 'I',
            'LugarExpedicion'   => $invoice->fiscalProfile->postal_code ?? '',
            'Exportacion'       => '01',
            'FormaPago'         => $invoice->payment_form,
            'MetodoPago'        => $invoice->payment_method,
            'Moneda'            => $invoice->currency,
            'SubTotal'          => (float) $invoice->subtotal,
            'Descuento'         => (float) $invoice->discount_total,
            'Total'             => (float) $invoice->total,
            'Sello'             => '',
            'Certificado'       => '',
            'NoCertificado'     => '',
            'Emisor' => [
                'Rfc'           => $invoice->fiscalProfile->rfc,
                'Nombre'        => $invoice->fiscalProfile->razon_social,
                'RegimenFiscal' => $invoice->fiscalProfile->regimen_fiscal,
            ],
            'Receptor' => [
                'Rfc'                     => $invoice->receiver_rfc,
                'Nombre'                  => $invoice->receiver_legal_name,
                'DomicilioFiscalReceptor' => $invoice->receiver_postal_code,
                'RegimenFiscalReceptor'   => $invoice->receiver_tax_regime,
                'UsoCFDI'                 => $invoice->cfdi_use,
            ],
            'Conceptos' => $conceptos,
        ];

        // Remove optional null values
        if (($invoice->payment_form ?? '') === '99') {
            $payload['CondicionesDePago'] = 'Contado';
        }

        return $payload;
    }

    /**
     * Stamp the invoice via SW Sapien HTTP API.
     *
     * @throws \RuntimeException when config is missing or the PAC rejects the request.
     */
    public function stamp(Invoice $invoice): void
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'SW Sapien no está configurado. Define SW_SAPIEN_ENDPOINT y SW_SAPIEN_TOKEN en .env.'
            );
        }

        $payload = $this->buildPayload($invoice);

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/jsontoxml'])
            ->post($endpoint . '/v4/cfdi40/issue/json', $payload);

        if ($response->failed()) {
            Log::error("SW Sapien stamping failed for invoice {$invoice->id}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException(
                'El PAC rechazó el timbrado: ' . ($response->json('message') ?? $response->body())
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            Log::error("SW Sapien stamping error for invoice {$invoice->id}", ['response' => $data]);
            throw new \RuntimeException(
                $data['message'] ?? 'Error desconocido al timbrar la factura.'
            );
        }

        $uuid  = $data['data']['tfd']['UUID'] ?? $data['data']['uuid'] ?? null;
        $xml   = $data['data']['cfdi'] ?? null;
        $pdfUrl = $data['data']['pdf'] ?? null;

        if (! $uuid || ! $xml) {
            throw new \RuntimeException('La respuesta del PAC no contiene UUID o XML.');
        }

        // Store XML locally
        $xmlPath = 'invoices/xml/' . $uuid . '.xml';
        Storage::disk('public')->put($xmlPath, $xml);

        $invoice->update([
            'uuid'      => $uuid,
            'xml_url'   => $xmlPath,
            'pdf_url'   => $pdfUrl,
            'status'    => InvoiceStatus::CERTIFIED,
            'issued_at' => now(),
        ]);
    }

    /**
     * Cancel a CFDI via SW Sapien HTTP API (UUID-based, CSDs precargados).
     *
     * @throws \RuntimeException
     */
    public function cancel(Invoice $invoice, string $emitterRfc, string $reason, ?string $substitutionUuid = null): void
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('SW Sapien no está configurado.');
        }

        $url = $endpoint . '/v4/cfdi40/cancel/'
            . $emitterRfc . '/'
            . $invoice->uuid . '/'
            . $reason . '/'
            . ($substitutionUuid ?? '');

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(rtrim($url, '/'), [
                'rfc'              => $emitterRfc,
                'uuid'             => $invoice->uuid,
                'motivo'           => $reason,
                'foliosustitucion' => $substitutionUuid,
            ]);

        if ($response->failed()) {
            Log::error("SW Sapien cancellation failed for invoice {$invoice->id}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException(
                'El PAC rechazó la cancelación: ' . ($response->json('message') ?? $response->body())
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            Log::error("SW Sapien cancellation error for invoice {$invoice->id}", ['response' => $data]);
            throw new \RuntimeException(
                $data['message'] ?? 'Error desconocido al cancelar la factura.'
            );
        }

        $invoice->update([
            'status'              => InvoiceStatus::CANCELED,
            'cancellation_reason' => $reason,
            'canceled_at'         => now(),
        ]);
    }

    /**
     * Auto-update the Customer model with fiscal data from the invoice form
     * when the data differs or was previously missing.
     */
    public function syncCustomerFiscalData(int $customerId, array $data): void
    {
        $customer = Customer::find($customerId);
        if (! $customer) return;

        $updates = [];

        if (! empty($data['receiver_rfc']) && $data['receiver_rfc'] !== $customer->tax_id) {
            $updates['tax_id'] = $data['receiver_rfc'];
        }
        if (! empty($data['receiver_legal_name']) && $data['receiver_legal_name'] !== $customer->company_name) {
            $updates['company_name'] = $data['receiver_legal_name'];
        }
        if (! empty($data['receiver_tax_regime']) && $data['receiver_tax_regime'] !== $customer->tax_regime) {
            $updates['tax_regime'] = $data['receiver_tax_regime'];
        }
        if (! empty($data['receiver_postal_code'])) {
            $addr = $customer->address ?? [];
            $currentZip = $addr['zip_code'] ?? '';
            if ($data['receiver_postal_code'] !== $currentZip) {
                $addr['zip_code'] = $data['receiver_postal_code'];
                $updates['address'] = $addr;
            }
        }

        if (! empty($updates)) {
            $customer->update($updates);
        }
    }

    /**
     * Upload CSD certificates (.cer and .key) for a fiscal profile's
     * sub-user account in SW Sapien.
     *
     * @param FiscalProfile $profile   The fiscal profile with sw_user_id already set.
     * @param string        $cerPath   Absolute path to the .cer file on disk.
     * @param string        $keyPath   Absolute path to the .key file on disk.
     * @param string        $password  CSD private key password.
     *
     * @throws \RuntimeException When config is missing or PAC rejects.
     *
     * @return array Mock CSD validation data while PAC sandbox access is pending.
     */
    public function uploadCsd(FiscalProfile $profile, string $cerPath, string $keyPath, string $password): array
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('SW Sapien no está configurado.');
        }

        if (! $profile->sw_user_id) {
            throw new \RuntimeException('El perfil fiscal no tiene un sw_user_id. Aprovisiona la subcuenta primero.');
        }

        /*
        // ── Real HTTP call (commented until PAC enables reseller token) ──
        $response = Http::withToken($token)
            ->attach('cer', file_get_contents($cerPath), basename($cerPath))
            ->attach('key', file_get_contents($keyPath), basename($keyPath))
            ->post($endpoint . '/v2/csd/upload', [
                'userId'   => $profile->sw_user_id,
                'rfc'      => $profile->rfc,
                'password' => $password,
            ]);

        if ($response->failed()) {
            Log::error('SW Sapien CSD upload rejected', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'sw_user_id'        => $profile->sw_user_id,
                'http_status'       => $response->status(),
                'body'              => $response->body(),
            ]);

            throw new \RuntimeException(
                'El PAC rechazó la carga del CSD: '
                . ($response->json('message') ?? $response->body())
            );
        }
        */

        // ── MOCK: Simulate successful CSD validation ──
        $mockResult = [
            'status'             => 'success',
            'message'            => 'Certificado cargado y validado con éxito en el PAC.',
            'certificate_number' => '00001000000504455667',
            'valid_from'         => now()->toDateTimeString(),
            'valid_to'           => now()->addYears(4)->toDateTimeString(),
        ];

        Log::info('SW Sapien CSD uploaded successfully (MOCK)', [
            'fiscal_profile_id'  => $profile->id,
            'rfc'                => $profile->rfc,
            'sw_user_id'         => $profile->sw_user_id,
            'certificate_number' => $mockResult['certificate_number'],
        ]);

        return $mockResult;
    }
    /**
     * Generate the next consecutive folio for a branch.
     */
    private function generateFolio(int $branchId): string
    {
        $lastInvoice = Invoice::where('branch_id', $branchId)
            ->orderByDesc('id')
            ->first();

        return (string) (($lastInvoice ? ((int) $lastInvoice->folio) + 1 : 1));
    }
}
