<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Billing\FiscalProfile;
use App\Models\Billing\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SWSapienService
{
    /**
     * Persist a new invoice and its line items in the database.
     *
     * Accepts the full validated form payload — including exportacion,
     * unit_name, no_identificacion, and per-item retentions — and computes
     * all monetary totals server-side with 6-decimal rate precision.
     */
    public function createInvoice(array $data, int $branchId): Invoice
    {
        $subtotal      = 0;
        $discountTotal = 0;
        $taxesTotal    = 0;
        $retainedTotal = 0;
        $grandTotal    = 0;

        $invoice = Invoice::create([
            'branch_id'           => $branchId,
            'fiscal_profile_id'   => $data['fiscal_profile_id'] ?? null,
            'customer_id'         => $data['customer_id'] ?? null,
            'series'              => $data['series'] ?? null,
            'folio'               => $this->generateFolio($branchId),
            'status'              => InvoiceStatus::DRAFT,
            'receiver_rfc'        => $data['receiver_rfc'],
            'receiver_legal_name'  => $data['receiver_legal_name'],
            'receiver_tax_regime'  => $data['receiver_tax_regime'],
            'receiver_postal_code' => $data['receiver_postal_code'],
            'cfdi_use'             => $data['cfdi_use'],
            'exportacion'          => $data['exportacion'] ?? '01',
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

        foreach ($data['items'] as $itemData) {
            // ── Transfer (traslado) ──
            $taxRate       = (float) ($itemData['tax_rate'] ?? 0.16);
            $lineDiscount  = (float) ($itemData['discount_amount'] ?? 0);
            $lineSubtotal  = round((float) $itemData['quantity'] * (float) $itemData['unit_price'], 2);
            $baseAfterDisc = $lineSubtotal - $lineDiscount;
            $hasTransfer   = ($itemData['objeto_imp'] ?? '02') === '02';
            $lineTaxAmount = $hasTransfer ? round($baseAfterDisc * $taxRate, 2) : 0;

            // ── Retention (retención) — from retentions array or legacy single fields ──
            $retentions = $itemData['retentions'] ?? [];

            // Backward compatibility: if no retentions array but legacy single fields exist, normalize
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
                'sat_unit_code'       => $itemData['sat_unit_code'],
                'unit_name'           => $itemData['unit_name'] ?? null,
                'unit_price'          => (float) $itemData['unit_price'],
                'subtotal'            => $lineSubtotal,
                'discount_amount'     => $lineDiscount,
                'tax_amount'          => $lineTaxAmount,
                'total'               => $lineTotal,
                'sat_product_code'    => $itemData['sat_product_code'],
                'no_identificacion'   => $itemData['no_identificacion'] ?? null,
                'objeto_imp'          => $itemData['objeto_imp'] ?? '02',
                'tax_type'            => $itemData['tax_type'] ?? '002',
                'tax_rate'            => $taxRate,
                'retained_tax_type'   => $normalizedRetentions[0]['type'] ?? null,
                'retained_tax_rate'   => $normalizedRetentions[0]['rate'] ?? null,
                'retained_tax_amount' => $normalizedRetentions[0]['amount'] ?? 0,
                'retentions'          => ! empty($normalizedRetentions) ? $normalizedRetentions : null,
            ]);

            $subtotal      += $lineSubtotal;
            $discountTotal += $lineDiscount;
            $taxesTotal    += $lineTaxAmount;
            $retainedTotal += $lineRetainedTotal;
            $grandTotal    += $lineTotal;
        }

        $invoice->update([
            'subtotal'             => round($subtotal, 2),
            'discount_total'       => round($discountTotal, 2),
            'taxes_total'          => round($taxesTotal, 2),
            'retained_taxes_total' => round($retainedTotal, 2),
            'total'                => round($grandTotal, 2),
        ]);

        return $invoice;
    }

    /**
     * Build the SW Sapien CFDI 4.0 JSON payload.
     *
     * ── PAC formatting rules ──
     * All numeric values MUST be sent as strings with explicit decimal
     * formatting. Native PHP floats break SW Sapien validation.
     *  • Monetary amounts → 2 decimals: number_format($val, 2, '.', '')
     *  • Rates → 6 decimals: number_format($val, 6, '.', '')
     *  • Quantities → 4 decimals: number_format($val, 4, '.', '')
     *  • TipoCambio → "1" when MXN
     *
     * ── Root fields ──
     * Version, Serie, Folio, Fecha are mandatory per SAT Anexo 20.
     * Exportacion is dynamic from the invoice record.
     *
     * Global Impuestos node aggregates traslados and retenciones by
     * (Impuesto, TipoFactor, TasaOCuota) per SAT Anexo 20 grouping rules.
     */
    public function buildPayload(Invoice $invoice): array
    {
        $invoice->load(['items', 'fiscalProfile']);

        if (! $invoice->fiscalProfile) {
            throw new \RuntimeException(
                'La factura no tiene un perfil fiscal asociado. Asigna un RFC emisor antes de timbrar.'
            );
        }

        $fmt  = fn (float $v, int $d = 2) => number_format($v, $d, '.', '');
        $fmt6 = fn (float $v) => $fmt($v, 6);
        $fmt4 = fn (float $v) => $fmt($v, 4);

        // ── Aggregators for global Impuestos node ──
        $globalTraslados   = [];
        $globalRetenciones = [];
        $totalTrasladados  = 0.0;
        $totalRetenidos    = 0.0;

        $conceptos = $invoice->items->map(function (InvoiceItem $item) use (
            &$globalTraslados, &$globalRetenciones, &$totalTrasladados, &$totalRetenidos, $fmt, $fmt6, $fmt4
        ) {
            $base = round((float) $item->subtotal - (float) $item->discount_amount, 2);

            $concepto = [
                'ClaveProdServ'   => $item->sat_product_code,
                'NoIdentificacion' => $item->no_identificacion ?: null,
                'ClaveUnidad'     => $item->sat_unit_code,
                'Unidad'          => $item->unit_name ?: null,
                'Cantidad'        => $fmt4((float) $item->quantity),
                'Descripcion'     => $item->description,
                'ValorUnitario'   => $fmt4((float) $item->unit_price),
                'Importe'         => $fmt((float) $item->subtotal),
                'ObjetoImp'       => $item->objeto_imp ?: '02',
            ];

            // SAT Anexo 20: Descuento is forbidden when zero — omit entirely
            if ((float) $item->discount_amount > 0) {
                $concepto['Descuento'] = $fmt((float) $item->discount_amount);
            }

            // Remove null optional keys per SAT strictness
            if ($concepto['NoIdentificacion'] === null) unset($concepto['NoIdentificacion']);
            if ($concepto['Unidad'] === null)           unset($concepto['Unidad']);

            // ── Per-item Impuestos sub-node ──
            $traslados   = [];
            $retenciones = [];

            // Transfer (traslado) — SAT requires the node whenever ObjetoImp = 02,
            // even when the rate is 0 % (tax_amount = 0). Use tax_type/tax_rate
            // defaults as supplied by the CFDI 4.0 catalogs.
            if ($item->objeto_imp === '02') {
                $effTaxRate   = (float) ($item->tax_rate ?? 0.16);
                $effTaxAmount = (float) $item->tax_amount;

                $traslado = [
                    'Base'       => $fmt($base),
                    'Impuesto'   => $item->tax_type ?: '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => $fmt6($effTaxRate),
                    'Importe'    => $fmt($effTaxAmount),
                ];

                $traslados[] = $traslado;

                $key = implode('|', [$traslado['Impuesto'], $traslado['TipoFactor'], $traslado['TasaOCuota']]);
                if (! isset($globalTraslados[$key])) {
                    $globalTraslados[$key] = [
                        'Base'       => 0.0,
                        'Impuesto'   => $traslado['Impuesto'],
                        'TipoFactor' => $traslado['TipoFactor'],
                        'TasaOCuota' => (float) $traslado['TasaOCuota'],
                        'Importe'    => 0.0,
                    ];
                }
                $globalTraslados[$key]['Base']    = round($globalTraslados[$key]['Base'] + (float) $traslado['Base'], 2);
                $globalTraslados[$key]['Importe'] = round($globalTraslados[$key]['Importe'] + (float) $traslado['Importe'], 2);
                $totalTrasladados = round($totalTrasladados + (float) $traslado['Importe'], 2);
            }

            // Retention (retención) — from retentions JSON array or legacy single fields
            $retentions = $item->retentions ?? [];

            // Backward compatibility: normalize legacy single retention fields
            if (empty($retentions) && $item->retained_tax_type && (float) $item->retained_tax_amount > 0) {
                $retentions = [[
                    'type'   => $item->retained_tax_type,
                    'rate'   => (float) ($item->retained_tax_rate ?: 0),
                    'amount' => (float) $item->retained_tax_amount,
                ]];
            }

            foreach ($retentions as $ret) {
                $retType   = $ret['type'] ?? null;
                $retRate   = (float) ($ret['rate'] ?? 0);
                $retAmount = (float) ($ret['amount'] ?? 0);

                if (! $retType || $retAmount <= 0) {
                    continue;
                }

                $retencion = [
                    'Base'       => $fmt($base),
                    'Impuesto'   => $retType,
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => $fmt6($retRate),
                    'Importe'    => $fmt($retAmount),
                ];

                $retenciones[] = $retencion;

                // Global retenciones: aggregate by Impuesto only (SAT groups
                // retentions by tax type, not by rate — per Anexo 20).
                $key = $retencion['Impuesto'];
                if (! isset($globalRetenciones[$key])) {
                    $globalRetenciones[$key] = [
                        'Impuesto' => $retencion['Impuesto'],
                        'Importe'  => 0.0,
                    ];
                }
                $globalRetenciones[$key]['Importe'] = round($globalRetenciones[$key]['Importe'] + (float) $retencion['Importe'], 2);
                $totalRetenidos = round($totalRetenidos + (float) $retencion['Importe'], 2);
            }

            if (count($traslados) || count($retenciones)) {
                $concepto['Impuestos'] = [];
                if (count($traslados))   $concepto['Impuestos']['Traslados']   = $traslados;
                if (count($retenciones)) $concepto['Impuestos']['Retenciones'] = $retenciones;
            }

            return $concepto;
        })->values()->toArray();

        // ── Root payload with all mandatory SAT fields ──
        $payload = [
            'Version'           => '4.0',
            'Folio'             => $invoice->folio,
            'Fecha'             => $invoice->fecha ?: now()->format('Y-m-d\TH:i:s'),
            'TipoDeComprobante' => 'I',
            'LugarExpedicion'   => $invoice->fiscalProfile->postal_code ?? '',
            'Exportacion'       => $invoice->exportacion ?: '01',
            'FormaPago'         => $invoice->payment_form,
            'MetodoPago'        => $invoice->payment_method,
            'Moneda'            => $invoice->currency,
            'SubTotal'          => $fmt((float) $invoice->subtotal),
            'Total'             => $fmt((float) $invoice->total),
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

        // Incluir la Serie únicamente si tiene un valor asignado (evita strings vacíos "")
        if (! empty($invoice->series)) {
            $payload['Serie'] = $invoice->series;
        }

        // SAT Anexo 20: Descuento at root level only when > 0
        if ((float) $invoice->discount_total > 0) {
            $payload['Descuento'] = $fmt((float) $invoice->discount_total);
        }

        // TipoCambio: required for non-MXN currencies per SAT Anexo 20.
        // Uses the exchange rate captured from the invoice form, formatted to 6 decimals.
        if (($invoice->currency ?? 'MXN') !== 'MXN') {
            $exchangeRate = (float) ($invoice->exchange_rate ?? 1);

            if ($exchangeRate <= 0) {
                throw new \RuntimeException(
                    'El tipo de cambio es obligatorio para facturas en moneda extranjera. Captura el tipo de cambio en el formulario.'
                );
            }

            $payload['TipoCambio'] = $fmt6($exchangeRate);
        }

        // ── Global Impuestos node (only when totals > 0) ──
        if (count($globalTraslados) || count($globalRetenciones)) {
            $payload['Impuestos'] = [];

            if (count($globalTraslados)) {
                $formatted = array_map(fn (array $t) => [
                    'Base'       => $fmt($t['Base']),
                    'Impuesto'   => $t['Impuesto'],
                    'TipoFactor' => $t['TipoFactor'],
                    'TasaOCuota' => $fmt6($t['TasaOCuota']),
                    'Importe'    => $fmt($t['Importe']),
                ], array_values($globalTraslados));

                $payload['Impuestos']['TotalImpuestosTrasladados'] = $fmt($totalTrasladados);
                $payload['Impuestos']['Traslados'] = $formatted;
            }

            if (count($globalRetenciones)) {
                // Global retenciones only expose Impuesto + Importe per SW Sapien spec
                $formatted = array_map(fn (array $r) => [
                    'Impuesto' => $r['Impuesto'],
                    'Importe'  => $fmt($r['Importe']),
                ], array_values($globalRetenciones));

                $payload['Impuestos']['TotalImpuestosRetenidos'] = $fmt($totalRetenidos);
                $payload['Impuestos']['Retenciones'] = $formatted;
            }
        }

        // CondicionesDePago: only when payment_form = 99 AND payment_method = PUE
        // Per project documentation: "Contado" when both conditions are met.
        // Must NOT be sent in any other case — an empty or unexpected value
        // also causes PAC rejection.
        if (($invoice->payment_form ?? '') === '99' && ($invoice->payment_method ?? '') === 'PUE') {
            $payload['CondicionesDePago'] = 'Contado';
        }

        return $payload;
    }

    /**
     * Stamp the invoice via SW Sapien HTTP API.
     *
     * @throws \RuntimeException when config is missing, billing is disabled,
     *                           or the PAC rejects the request.
     */
    public function stamp(Invoice $invoice): void
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException(
                'El servicio de timbrado no está configurado. Contacta con soporte técnico.'
            );
        }

        // ── Guard: billing must be enabled on the subscription ──
        $subscription = $invoice->branch?->subscription;
        if (! $subscription || ! $subscription->facturacion_habilitada) {
            throw new \RuntimeException(
                'La facturación no está habilitada para esta cuenta. Actívala en Configuración > Facturación.'
            );
        }

        $payload = $this->buildPayload($invoice);

        // ── Authenticate as the subaccount so the PAC uses that subaccount's CSD and stamp quota ──
        if (! $invoice->fiscalProfile) {
            throw new \RuntimeException('La factura no tiene un perfil fiscal asociado.');
        }

        $subaccountToken = $this->authenticateSubaccount($invoice->fiscalProfile);

        $response = Http::withToken($subaccountToken)
            ->withHeaders([
                'Content-Type' => 'application/jsontoxml',
            ])
            ->post($endpoint . '/v3/cfdi33/issue/json/v4', $payload);

        if ($response->failed()) {
            $errorMsg = $response->json('message')
                ?? $response->json('messageDetail')
                ?? $response->body();

            Log::error("SW Sapien stamping failed for invoice {$invoice->id}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException(
                'Se rechazó el timbrado: ' . $errorMsg
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            Log::error('Se rechazó el timbrado', [
                'invoice_id'    => $invoice->id,
                'payload'       => $payload,
                'message'       => $data['message'] ?? null,
                'messageDetail' => $data['messageDetail'] ?? null,
                'http_status'   => $response->status(),
            ]);

            throw new \RuntimeException(
                'Se rechazó el timbrado: '
                . ($data['messageDetail'] ?? $data['message'] ?? $response->body())
            );
        }

        $cfdi   = $data['data']['cfdi'] ?? null;
        $uuid   = $data['data']['uuid'] ?? $data['data']['tfd']['UUID'] ?? null;
        $pdfUrl = $data['data']['pdf'] ?? null;

        if (! $uuid || ! $cfdi) {
            throw new \RuntimeException('El factura no se timbró correctamente. Intenta de nuevo.');
        }

        // ── Extract RfcProvCertif from cadenaOriginalSAT ──
        // Format: ||1.1|UUID|FechaTimbrado|RfcProvCertif|SelloCFD|NoCertificadoSAT||
        $cadenaOriginal = $data['data']['cadenaOriginalSAT'] ?? '';
        $cadenaParts = explode('|', $cadenaOriginal);
        $rfcProvCertif = $cadenaParts[5] ?? null; // index 5 because [0] and [1] are empty (leading ||)

        // Store the REAL CFDI XML (not an acuse)
        $xmlPath = 'invoices/xml/' . $uuid . '.xml';
        Storage::disk('public')->put($xmlPath, $cfdi);

        $invoice->update([
            'uuid'                => $uuid,
            'xml_url'             => $xmlPath,
            'pdf_url'             => $pdfUrl,
            'fecha_timbrado'      => $data['data']['fechaTimbrado'] ?? null,
            'sello_cfdi'           => $data['data']['selloCFDI'] ?? null,
            'sello_sat'            => $data['data']['selloSAT'] ?? null,
            'no_certificado_sat'   => $data['data']['noCertificadoSAT'] ?? null,
            'rfc_prov_certif'      => $rfcProvCertif,
            'cadena_original_sat'  => $cadenaOriginal ?: null,
            'qr_code_base64'       => $data['data']['qrCode'] ?? null,
            'status'               => InvoiceStatus::CERTIFIED,
            'issued_at'            => now(),
        ]);
    }

    /**
     * Cancel a CFDI via SW Sapien HTTP API (UUID-based, CSDs precargados).
     *
     * Returns the PAC response data so the caller can determine if the
     * cancelation requires receiver acceptance (isCancelable).
     *
     * @return array The full 'data' payload from the PAC response.
     *
     * @throws \RuntimeException
     */
    public function cancel(Invoice $invoice, string $emitterRfc, string $reason, ?string $substitutionUuid = null): array
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de timbrado no está configurado. Contacta con soporte técnico.');
        }

        // ── Guard: billing must be enabled on the subscription ──
        $subscription = $invoice->branch?->subscription;
        if (! $subscription || ! $subscription->facturacion_habilitada) {
            throw new \RuntimeException(
                'La facturación no está habilitada para esta cuenta. Actívala en Configuración > Facturación.'
            );
        }

        $url = $endpoint . '/cfdi33/cancel/'
            . $emitterRfc . '/'
            . $invoice->uuid . '/'
            . $reason . '/'
            . ($substitutionUuid ?? '');

        // ── Authenticate as the subaccount so the PAC uses that subaccount's CSD ──
        if (! $invoice->fiscalProfile) {
            throw new \RuntimeException('La factura no tiene un perfil fiscal asociado.');
        }

        $subaccountToken = $this->authenticateSubaccount($invoice->fiscalProfile);

        $response = Http::withToken($subaccountToken)
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
                'Se rechazó la cancelación: ' . ($response->json('message') ?? $response->body())
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            Log::error("SW Sapien cancellation error for invoice {$invoice->id}", ['response' => $data]);
            throw new \RuntimeException(
                $data['message'] ?? 'No se pudo cancelar la factura. Intenta de nuevo.'
            );
        }

        // Return the full data payload so the caller can inspect
        // isCancelable, statusCancelation, etc.
        return $data['data'] ?? $data;
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
     * Uses subaccount-level authentication (not the dealer token) so the
     * CSD is stored under the correct subaccount and can be used for
     * stamping with that subaccount's own quota.
     *
     * Certificate metadata (serial number, validity) is extracted locally
     * via openssl_x509_parse() because the PAC's `/certificates/save`
     * response only returns a confirmation message, not the certificate
     * fields.
     *
     * @param FiscalProfile $profile   The fiscal profile with sw_user_id already set.
     * @param string        $cerPath   Absolute path to the .cer file on disk.
     * @param string        $keyPath   Absolute path to the .key file on disk.
     * @param string        $password  CSD private key password.
     *
     * @throws \RuntimeException When config is missing, subaccount auth fails,
     *                           or the PAC rejects the request.
     *
     * @return array With status, message, certificate_number, valid_from, valid_to.
     */
    public function uploadCsd(FiscalProfile $profile, string $cerPath, string $keyPath, string $password): array
    {
        $endpoint = config('services.swsapien.endpoint');
        $token    = config('services.swsapien.token');

        if (! $endpoint || ! $token) {
            throw new \RuntimeException('El servicio de timbrado no está configurado. Contacta con soporte técnico.');
        }

        if (! $profile->sw_user_id) {
            throw new \RuntimeException('El RFC no está vinculado al servicio de timbrado. Configura tu información fiscal e intenta de nuevo.');
        }

        $cerContent = file_get_contents($cerPath);
        $keyContent = file_get_contents($keyPath);

        if (! $cerContent || ! $keyContent) {
            throw new \RuntimeException('No se pudieron leer los archivos del CSD.');
        }

        // ── Convert PEM → raw DER binary before base64-encoding ──
        $cerDer = $this->extractDerFromPem($cerContent, 'CERTIFICATE');
        $keyDer = $this->extractDerFromPem($keyContent, 'PRIVATE KEY');

        $payload = [
            'b64Key'   => base64_encode($keyDer),
            'b64Cer'   => base64_encode($cerDer),
            'password' => $password,
            'type'     => 'stamp',
        ];

        // ── Authenticate as the subaccount (not the dealer) ──
        $subaccountToken = $this->authenticateSubaccount($profile);

        $response = Http::withToken($subaccountToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($endpoint . '/certificates/save', $payload);

        if ($response->failed()) {
            Log::error('SW Sapien CSD upload rejected (HTTP error)', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'sw_user_id'        => $profile->sw_user_id,
                'http_status'       => $response->status(),
                'response_json'     => $response->json(),
                'response_body'     => $response->body(),
            ]);

            throw new \RuntimeException(
                'Se rechazaron los certificados: '
                . ($response->json('message') ?? $response->body())
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') !== 'success') {
            Log::error('SW Sapien CSD upload rejected (status != success)', [
                'fiscal_profile_id' => $profile->id,
                'rfc'               => $profile->rfc,
                'sw_user_id'        => $profile->sw_user_id,
                'response'          => $data,
            ]);

            throw new \RuntimeException(
                'Se rechazaron los certificados: '
                . ($data['message'] ?? $data['data'] ?? json_encode($data))
            );
        }

        // Extract certificate metadata locally (PAC only returns a success message)
        return $this->processCsdResponse($profile, $data, $cerDer);
    }

    /**
     * Authenticate as a subaccount and return a time-limited token.
     *
     * SW Sapien Esquema 2 (subaccounts with their own stamp quota) requires
     * authenticating as the specific subaccount before uploading CSDs or
     * stamping invoices. The dealer token cannot be used for these operations.
     *
     * Tokens are cached for 110 minutes (the PAC grants 2-hour validity)
     * to avoid unnecessary authentication round-trips.
     *
     * @throws \RuntimeException When the subaccount credentials are missing
     *                           or the PAC rejects authentication.
     */
    protected function authenticateSubaccount(FiscalProfile $profile): string
    {
        $cacheKey = "sw_subaccount_token_{$profile->id}";

        return Cache::remember($cacheKey, now()->addMinutes(110), function () use ($profile) {
            $endpoint = config('services.swsapien.endpoint');

            if (! $endpoint) {
                throw new \RuntimeException('El servicio de timbrado no está configurado. Contacta con soporte técnico.');
            }

            // The subaccount user is the email stored during provisioning
            $subaccountUser = $profile->sw_account_email ?? $profile->email;

            if (! $subaccountUser || ! $profile->password) {
                throw new \RuntimeException(
                    'El RFC no está vinculado al servicio de timbrado. '
                    . 'Configura tu información fiscal.'
                );
            }

            $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint . '/v2/security/authenticate', [
                    'user'     => $subaccountUser,
                    'password' => $profile->password,
                ]);

            if (! $response->successful()) {
                Log::error('SW Sapien subaccount authentication failed', [
                    'fiscal_profile_id' => $profile->id,
                    'sw_user_id'        => $profile->sw_user_id,
                    'http_status'       => $response->status(),
                    'response_body'     => $response->body(),
                ]);

                throw new \RuntimeException(
                    'No se pudo validar tu RFC. Verifica que los certificados CSD estén vigentes y la contraseña sea correcta.'
                );
            }

            $authData = $response->json();

            // SW Sapien V2 auth returns: { "data": { "token": "..." }, "status": "success" }
            $token = $authData['data']['token']
                ?? $authData['token']
                ?? null;

            if (! $token) {
                Log::error('SW Sapien subaccount auth response missing token', [
                    'fiscal_profile_id' => $profile->id,
                    'response'          => $authData,
                ]);

                throw new \RuntimeException(
                    'El servicio de validación fiscal no respondió correctamente. Intenta de nuevo.'
                );
            }

            Log::info('SW Sapien subaccount authenticated', [
                'fiscal_profile_id' => $profile->id,
                'sw_user_id'        => $profile->sw_user_id,
            ]);

            return $token;
        });
    }

    /**
     * Process the PAC response after a successful CSD upload.
     *
     * The PAC only returns a confirmation message ("CSD Guardados Correctamente.").
     * Certificate metadata (serial number, validity dates) must be extracted
     * locally from the .cer file via openssl_x509_parse().
     *
     * This method persists the extracted data to the FiscalProfile model
     * so the frontend (Billing Settings) can display the
     * "Certificado activo" block with proper certificate_number, valid_from,
     * and valid_to fields.
     *
     * @param  FiscalProfile $profile     The fiscal profile to update.
     * @param  array         $pacResponse  The decoded JSON response from /certificates/save.
     * @param  string        $cerDer       Raw DER binary of the uploaded .cer file.
     * @return array                       Normalized result for the controller/frontend.
     */
    protected function processCsdResponse(FiscalProfile $profile, array $pacResponse, string $cerDer): array
    {
        // Extract certificate data locally — the PAC response does not include
        // certificate_number, valid_from, or valid_to.
        $certInfo = $this->extractCertificateData($cerDer);

        // Persist so the frontend (Billing Settings) can display the "Certificado activo" block
        $profile->update([
            'certificate_number' => $certInfo['certificate_number'],
            'valid_from'         => $certInfo['valid_from'],
            'valid_to'           => $certInfo['valid_to'],
        ]);

        Log::info('SW Sapien CSD uploaded and certificate data persisted', [
            'fiscal_profile_id'  => $profile->id,
            'rfc'                => $profile->rfc,
            'sw_user_id'         => $profile->sw_user_id,
            'certificate_number' => $certInfo['certificate_number'],
        ]);

        return [
            'status'             => $pacResponse['status'] ?? 'success',
            'message'            => $pacResponse['data'] ?? $pacResponse['message'] ?? 'CSD guardados correctamente.',
            'certificate_number' => $certInfo['certificate_number'],
            'valid_from'         => $certInfo['valid_from']->toDateString(),
            'valid_to'           => $certInfo['valid_to']->toDateString(),
        ];
    }

    /**
     * Extract certificate serial number and validity period directly from
     * the .cer file using openssl_x509_parse().
     *
     * CSD files from the SAT come in DER format. openssl_x509_parse() expects
     * PEM, so the DER binary is converted to PEM before parsing.
     *
     * @param  string $cerDer  Raw DER binary of the certificate.
     * @return array           With certificate_number, valid_from (Carbon), valid_to (Carbon).
     *
     * @throws \RuntimeException When the certificate cannot be parsed.
     */
    protected function extractCertificateData(string $cerDer): array
    {
        // Convert DER → PEM for openssl_x509_parse()
        $pem = "-----BEGIN CERTIFICATE-----\n"
             . chunk_split(base64_encode($cerDer), 64, "\n")
             . "-----END CERTIFICATE-----\n";

        $certData = openssl_x509_parse($pem);

        if (! $certData) {
            throw new \RuntimeException(
                'No se pudo interpretar el certificado (.cer) para extraer su vigencia y número de serie.'
            );
        }

        $certNumber = $certData['serialNumberHex'] ?? $certData['serialNumber'] ?? null;

        // X.509 serialNumberHex is a 40-char hex string representing 20 octets.
        // The database column certificate_number is VARCHAR(20) and expects the
        // raw 20-byte decimal representation, so we convert the hex to binary.
        if ($certNumber && strlen($certNumber) === 40 && ctype_xdigit($certNumber)) {
            $certNumber = hex2bin($certNumber);
        }

        return [
            'certificate_number' => $certNumber,
            'valid_from'         => \Carbon\Carbon::createFromTimestamp($certData['validFrom_time_t']),
            'valid_to'           => \Carbon\Carbon::createFromTimestamp($certData['validTo_time_t']),
        ];
    }

    /**
     * Strip PEM armor and return raw DER binary content.
     *
     * CSD files from the SAT may arrive in two formats:
     *  - Raw DER binary (already fine — returned as-is).
     *  - PEM-armored (base64 between -----BEGIN/END----- headers).
     *
     * SW Sapien requires pure DER, so PEM must be decoded first.
     *
     * @param  string $content Raw file bytes.
     * @param  string $label   PEM label (CERTIFICATE or PRIVATE KEY).
     * @return string          Raw DER binary.
     */
    private function extractDerFromPem(string $content, string $label): string
    {
        // Detect PEM armor by looking for the BEGIN header
        if (str_contains($content, '-----BEGIN ' . $label . '-----')) {
            // Strip headers, footers, and all whitespace / line breaks
            $content = preg_replace('/-----BEGIN ' . $label . '-----/', '', $content);
            $content = preg_replace('/-----END ' . $label . '-----/', '', $content);
        }

        // Remove any remaining whitespace, newlines, or carriage returns
        $cleaned = preg_replace('/\s+/', '', $content);

        // If the result looks like base64, decode to DER; otherwise it's already DER
        if (preg_match('/^[a-zA-Z0-9+\/=]+$/', $cleaned) && strlen($cleaned) > 64) {
            $decoded = base64_decode($cleaned, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        // Already raw binary DER — return as-is
        return $content;
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
