<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $invoice->series ? $invoice->series . ' ' : '' }}{{ $invoice->folio }}</title>
    <style>
        /* ── Reset & Base ── */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-size: 9px;
            color: #1a1a1a;
            line-height: 1.4;
            padding: 0;
        }

        /* ── Page container ── */
        .page {
            width: 100%;
            max-width: 216mm; /* letter width */
            margin: 0 auto;
            padding: 10mm 12mm;
        }

        /* ── Header ── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6mm;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 4mm;
        }

        .header-logo {
            max-width: 55mm;
            max-height: 22mm;
        }

        .header-logo img {
            max-width: 100%;
            max-height: 22mm;
            object-fit: contain;
        }

        .header-logo-placeholder {
            width: 50mm;
            height: 15mm;
        }

        .header-info {
            text-align: right;
        }

        .header-info h1 {
            font-size: 14px;
            font-weight: 700;
            color: #111;
            margin-bottom: 1mm;
            letter-spacing: 0.3px;
        }

        .header-info .serie-folio {
            font-size: 18px;
            font-weight: 300;
            color: #333;
            letter-spacing: 1px;
        }

        .header-info .fecha {
            font-size: 7.5px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-top: 1mm;
        }

        /* ── Data panels (Emisor / Receptor / Timbre) ── */
        .data-grid {
            display: flex;
            gap: 3mm;
            margin-bottom: 4mm;
        }

        .data-panel {
            flex: 1;
            border: 1px solid #d5d5d5;
            border-radius: 3px;
            overflow: hidden;
        }

        .data-panel-header {
            background: #f5f5f5;
            padding: 1.5mm 3mm;
            font-size: 7.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #555;
            border-bottom: 1px solid #d5d5d5;
        }

        .data-panel-body {
            padding: 2mm 3mm;
        }

        .data-row {
            display: flex;
            margin-bottom: 0.8mm;
            font-size: 7.5px;
        }

        .data-row:last-child {
            margin-bottom: 0;
        }

        .data-label {
            width: 28mm;
            flex-shrink: 0;
            color: #888;
            text-transform: uppercase;
            font-size: 6.5px;
            letter-spacing: 0.8px;
        }

        .data-value {
            color: #222;
            font-weight: 500;
        }

        .data-value.rfc {
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }

        .data-value.uuid {
            font-family: 'Courier New', monospace;
            font-size: 7px;
            word-break: break-all;
        }

        /* ── Concepts table ── */
        .concepts-section {
            margin-bottom: 4mm;
        }

        .concepts-section h2 {
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #555;
            margin-bottom: 1.5mm;
            padding: 1.5mm 3mm;
            background: #f5f5f5;
            border: 1px solid #d5d5d5;
            border-radius: 3px 3px 0 0;
        }

        .concepts-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7px;
            border: 1px solid #d5d5d5;
        }

        .concepts-table thead th {
            background: #fafafa;
            padding: 1.5mm 2mm;
            text-align: center;
            font-size: 6.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #666;
            border-bottom: 1px solid #d5d5d5;
            white-space: nowrap;
        }

        .concepts-table thead th:first-child {
            text-align: left;
            padding-left: 3mm;
        }

        .concepts-table tbody td {
            padding: 1.5mm 2mm;
            border-bottom: 1px solid #eee;
            text-align: center;
            vertical-align: top;
            color: #333;
        }

        .concepts-table tbody td:first-child {
            text-align: left;
            padding-left: 3mm;
        }

        .concepts-table tbody tr:last-child td {
            border-bottom: none;
        }

        .concepts-table .text-right {
            text-align: right !important;
        }

        .concepts-table .text-left {
            text-align: left !important;
        }

        .concepts-table .mono {
            font-family: 'Courier New', monospace;
            font-size: 6.5px;
        }

        .concepts-table .desc {
            max-width: 55mm;
            word-wrap: break-word;
        }

        .concepts-table .tax-detail {
            font-size: 6px;
            color: #888;
            display: block;
            margin-top: 0.3mm;
        }

        /* ── Totals ── */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 5mm;
        }

        .totals-table {
            width: 70mm;
            border-collapse: collapse;
            font-size: 7.5px;
            border: 1px solid #d5d5d5;
            border-radius: 3px;
            overflow: hidden;
        }

        .totals-table td {
            padding: 1.5mm 3mm;
            border-bottom: 1px solid #eee;
        }

        .totals-table td:first-child {
            color: #777;
            font-weight: 500;
        }

        .totals-table td:last-child {
            text-align: right;
            font-weight: 600;
            color: #222;
        }

        .totals-table tr:last-child td {
            border-bottom: none;
            font-size: 10px;
            font-weight: 700;
            background: #fafafa;
            color: #111;
        }

        .totals-table .impuestos-header td {
            background: #f5f5f5;
            font-size: 6.5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
            font-weight: 700;
            padding-top: 2mm;
        }

        .totals-table .retencion td:first-child {
            color: #c0392b;
        }

        .totals-table .retencion td:last-child {
            color: #c0392b;
        }

        .total-letra {
            margin-top: 2mm;
            padding: 2mm 3mm;
            font-size: 6.5px;
            color: #888;
            text-align: right;
            font-style: italic;
            line-height: 1.5;
        }

        /* ── QR & Seals section ── */
        .seals-section {
            display: flex;
            gap: 3mm;
            margin-bottom: 4mm;
        }

        .qr-box {
            width: 32mm;
            height: 32mm;
            border: 1px solid #d5d5d5;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #fafafa;
        }

        .qr-box img {
            width: 28mm;
            height: 28mm;
        }

        .qr-box .qr-placeholder {
            font-size: 6px;
            color: #ccc;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .seals-stack {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1.5mm;
        }

        .seal-item {
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }

        .seal-item-header {
            background: #fafafa;
            padding: 1mm 3mm;
            font-size: 6.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #777;
            border-bottom: 1px solid #e0e0e0;
        }

        .seal-item-body {
            padding: 1.5mm 3mm;
            font-family: 'Courier New', monospace;
            font-size: 5.5px;
            color: #aaa;
            word-break: break-all;
            line-height: 1.3;
            max-height: 12mm;
            overflow: hidden;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 5mm;
            padding-top: 3mm;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            font-size: 6px;
            color: #bbb;
            line-height: 1.6;
        }

        .footer strong {
            color: #999;
            font-weight: 500;
        }

        /* ── Print optimization ── */
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                background: white;
            }
            .page {
                padding: 5mm 8mm;
            }
        }

        @page {
            size: letter;
            margin: 8mm;
        }
    </style>
</head>
<body>
    <div class="page">

        {{-- ════════════════════════════════════════
             HEADER — Logo + Serie/Folio
             ════════════════════════════════════════ --}}
        <div class="header">
            <div class="header-logo">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="Logo">
                @else
                    <div class="header-logo-placeholder"></div>
                @endif
            </div>
            <div class="header-info">
                <h1>Factura de {{ $invoice->fiscalProfile?->razon_social ?? 'Venta' }}</h1>
                <div class="serie-folio">
                    {{ $invoice->series ? $invoice->series . ' ' : '' }}{{ $invoice->folio }}
                </div>
                <div class="fecha">
                    Fecha de emisión: {{ \Carbon\Carbon::parse($invoice->created_at)->format('Y-m-d H:i:s') }}
                </div>
                <div style="font-size:6.5px; color:#999; margin-top:1mm;">
                    Tipo: {{ $invoice->exportacion === '01' ? 'No aplica' : ($invoice->exportacion === '02' ? 'Definitiva con clave A1' : ($invoice->exportacion === '03' ? 'Temporal' : 'Definitiva con clave distinta a A1')) }}
                    &nbsp;|&nbsp; Exportación: {{ $invoice->exportacion }}
                </div>
            </div>
        </div>

        {{-- ════════════════════════════════════════
             DATA GRID: Emisor | Receptor | Timbre
             ════════════════════════════════════════ --}}
        <div class="data-grid">

            {{-- Emisor --}}
            <div class="data-panel">
                <div class="data-panel-header">Emisor</div>
                <div class="data-panel-body">
                    <div class="data-row">
                        <span class="data-label">RFC</span>
                        <span class="data-value rfc">{{ $invoice->fiscalProfile?->rfc ?? '—' }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Razón social</span>
                        <span class="data-value">{{ $invoice->fiscalProfile?->razon_social ?? '—' }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Régimen fiscal</span>
                        <span class="data-value rfc">{{ $invoice->fiscalProfile?->regimen_fiscal ?? '—' }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Código postal</span>
                        <span class="data-value">{{ $invoice->fiscalProfile?->postal_code ?? '—' }}</span>
                    </div>
                    @if ($invoice->fiscalProfile?->certificate_number)
                    <div class="data-row">
                        <span class="data-label">No. certificado</span>
                        <span class="data-value mono" style="font-size:6.5px;">{{ $invoice->fiscalProfile->certificate_number }}</span>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Receptor --}}
            <div class="data-panel">
                <div class="data-panel-header">Receptor</div>
                <div class="data-panel-body">
                    <div class="data-row">
                        <span class="data-label">RFC</span>
                        <span class="data-value rfc">{{ $invoice->receiver_rfc }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Razón social</span>
                        <span class="data-value">{{ $invoice->receiver_legal_name }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Régimen fiscal</span>
                        <span class="data-value rfc">{{ $invoice->receiver_tax_regime }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Código postal</span>
                        <span class="data-value">{{ $invoice->receiver_postal_code }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Uso del CFDI</span>
                        <span class="data-value rfc">{{ $invoice->cfdi_use }}</span>
                    </div>
                </div>
            </div>

            {{-- Timbre Fiscal (PAC) --}}
            <div class="data-panel">
                <div class="data-panel-header">Timbre fiscal digital</div>
                <div class="data-panel-body">
                    <div class="data-row">
                        <span class="data-label">UUID</span>
                        <span class="data-value uuid">{{ $timbre['uuid'] }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Fecha timbrado</span>
                        <span class="data-value">{{ $timbre['fecha_timbrado'] }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">No. cert. SAT</span>
                        <span class="data-value mono" style="font-size:6.5px;">{{ $timbre['no_certificado_sat'] }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">RFC PAC</span>
                        <span class="data-value rfc">{{ $timbre['rfc_prov_certif'] }}</span>
                    </div>
                </div>
            </div>

        </div>

        {{-- ════════════════════════════════════════
             Payment info
             ════════════════════════════════════════ --}}
        <div style="display:flex; gap:3mm; margin-bottom:4mm; font-size:7px; color:#666;">
            <span>Forma de pago: <strong style="color:#333;">{{ $invoice->payment_form }}</strong></span>
            <span>Método de pago: <strong style="color:#333;">{{ $invoice->payment_method }}</strong></span>
            <span>Moneda: <strong style="color:#333;">{{ $invoice->currency }}</strong></span>
            @if ($invoice->payment_form === '99')
                <span>Condiciones de pago: <strong style="color:#333;">Contado</strong></span>
            @endif
        </div>

        {{-- ════════════════════════════════════════
             CONCEPTOS — Line items table
             ════════════════════════════════════════ --}}
        <div class="concepts-section">
            <h2>Conceptos</h2>
            <table class="concepts-table">
                <thead>
                    <tr>
                        <th style="width:10mm;">Clave SAT</th>
                        <th style="width:10mm;">No. ident.</th>
                        <th style="width:9mm;">Cantidad</th>
                        <th style="width:9mm;">Unidad SAT</th>
                        <th style="width:9mm;">Unidad</th>
                        <th style="width:auto;">Descripción</th>
                        <th style="width:11mm;">Valor unitario</th>
                        <th style="width:11mm;">Importe</th>
                        <th style="width:10mm;">Descuento</th>
                        <th style="width:14mm;">Impuestos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        @php
                            $lineImporte    = (float) $item->subtotal;
                            $lineDescuento  = (float) $item->discount_amount;
                            $lineTaxAmount  = (float) $item->tax_amount;
                            $lineRetAmount  = (float) $item->retained_tax_amount;
                            $taxRateDisplay = $item->tax_rate ? ((float) $item->tax_rate * 100) . '%' : '—';
                            $retRateDisplay = $item->retained_tax_rate ? ((float) $item->retained_tax_rate * 100) . '%' : '—';
                        @endphp
                        <tr>
                            <td class="mono">{{ $item->sat_product_code }}</td>
                            <td class="mono">{{ $item->no_identificacion ?: '—' }}</td>
                            <td>{{ number_format((float) $item->quantity, 4, '.', ',') }}</td>
                            <td class="mono">{{ $item->sat_unit_code }}</td>
                            <td>{{ $item->unit_name ?: '—' }}</td>
                            <td class="desc">{{ $item->description }}</td>
                            <td class="text-right mono">$ {{ number_format((float) $item->unit_price, 4, '.', ',') }}</td>
                            <td class="text-right mono">$ {{ number_format($lineImporte, 2, '.', ',') }}</td>
                            <td class="text-right mono">$ {{ number_format($lineDescuento, 2, '.', ',') }}</td>
                            <td class="text-left" style="font-size:6px;">
                                @if ($item->objeto_imp === '02' && $lineTaxAmount > 0)
                                    <span style="color:#2e7d32;">
                                        +{{ $item->tax_type ?: '002' }} ({{ $taxRateDisplay }}): ${{ number_format($lineTaxAmount, 2, '.', ',') }}
                                    </span>
                                @endif
                                @if ($item->objeto_imp === '02' && $lineTaxAmount > 0 && $lineRetAmount > 0)
                                    <br>
                                @endif
                                @if ($lineRetAmount > 0)
                                    <span style="color:#c0392b;">
                                        −{{ $item->retained_tax_type }} ({{ $retRateDisplay }}): ${{ number_format($lineRetAmount, 2, '.', ',') }}
                                    </span>
                                @endif
                                @if ($item->objeto_imp === '01')
                                    <span style="color:#888;">No objeto</span>
                                @elseif ($item->objeto_imp === '03' || ($item->objeto_imp === '02' && $lineTaxAmount <= 0 && $lineRetAmount <= 0))
                                    <span style="color:#888;">Exento</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ════════════════════════════════════════
             TOTALS
             ════════════════════════════════════════ --}}
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal</td>
                    <td>$ {{ number_format($subtotal, 2, '.', ',') }}</td>
                </tr>
                @if ($discountTotal > 0)
                <tr>
                    <td>Descuento</td>
                    <td style="color:#c0392b;">− $ {{ number_format($discountTotal, 2, '.', ',') }}</td>
                </tr>
                @endif

                {{-- Impuestos trasladados --}}
                @if (count($groupedTransfers))
                <tr class="impuestos-header">
                    <td colspan="2">Impuestos trasladados</td>
                </tr>
                @foreach ($groupedTransfers as $transfer)
                <tr>
                    <td style="font-size:7px; padding-left:5mm;">
                        {{ $transfer['impuesto'] }} ({{ $transfer['tipoFactor'] }} {{ number_format($transfer['tasaOCuota'] * 100, 0) }}%)
                    </td>
                    <td>$ {{ number_format($transfer['importe'], 2, '.', ',') }}</td>
                </tr>
                @endforeach
                @endif

                {{-- Impuestos retenidos --}}
                @if (count($groupedRetentions))
                <tr class="impuestos-header">
                    <td colspan="2">Impuestos retenidos</td>
                </tr>
                @foreach ($groupedRetentions as $retention)
                <tr class="retencion">
                    <td style="font-size:7px; padding-left:5mm;">{{ $retention['impuesto'] }}</td>
                    <td>− $ {{ number_format($retention['importe'], 2, '.', ',') }}</td>
                </tr>
                @endforeach
                @endif

                <tr>
                    <td>Total</td>
                    <td>$ {{ number_format($total, 2, '.', ',') }}</td>
                </tr>
            </table>
        </div>

        {{-- Total con letra --}}
        @php
            function numberToWordsSpanish($number) {
                if ($number == 0) return 'cero pesos 00/100 M.N.';

                $entero = floor($number);
                $centavos = round(($number - $entero) * 100);

                $unidades = ['', 'un', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve'];
                $decenas = ['', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
                $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
                $especiales = ['diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
                               'veinte', 'veintiún', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

                function convertirGrupo($n) {
                    global $unidades, $decenas, $centenas, $especiales;

                    if ($n == 100) return 'cien';
                    if ($n == 0) return '';

                    $resultado = '';
                    $c = floor($n / 100);
                    $d = floor(($n % 100) / 10);
                    $u = $n % 10;

                    if ($c > 0) $resultado .= $centenas[$c] . ' ';

                    if ($d == 1 && $u >= 0) {
                        $resultado .= $especiales[($n % 100) - 10] . ' ';
                    } elseif ($d == 2 && $u >= 1) {
                        $resultado .= $especiales[10 + $u] . ' ';
                    } else {
                        if ($d > 0) $resultado .= $decenas[$d] . ($u > 0 ? ' y ' : ' ');
                        if ($u > 0) $resultado .= $unidades[$u] . ' ';
                    }

                    return $resultado;
                }

                $resultado = '';

                if ($entero >= 1000000) {
                    $millones = floor($entero / 1000000);
                    if ($millones == 1) {
                        $resultado .= 'un millón ';
                    } else {
                        $resultado .= number_format($millones, 0) . ' millones ';
                    }
                    $entero %= 1000000;
                }

                if ($entero >= 1000) {
                    $miles = floor($entero / 1000);
                    if ($miles == 1) {
                        $resultado .= 'mil ';
                    } else {
                        $resultado .= convertirGrupo($miles) . ' mil ';
                    }
                    $entero %= 1000;
                }

                $resultado .= convertirGrupo($entero);

                $resultado = trim(preg_replace('/\s+/', ' ', $resultado));
                $resultado = ucfirst($resultado);

                $moneda = $invoice->currency === 'MXN' ? 'M.N.' : $invoice->currency;
                return $resultado . ' pesos ' . str_pad($centavos, 2, '0', STR_PAD_LEFT) . '/100 ' . $moneda;
            }
        @endphp

        <div class="total-letra">
            Importe con letra: {{ numberToWordsSpanish($total) }}
        </div>

        {{-- ════════════════════════════════════════
             QR + Sellos digitales
             ════════════════════════════════════════ --}}
        <div class="seals-section">
            <div class="qr-box">
                @if ($timbre['uuid'] && $timbre['uuid'] !== '—')
                    @php
                        $qrUrl = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx'
                            . '?id=' . urlencode($timbre['uuid'])
                            . '&re=' . urlencode($invoice->fiscalProfile?->rfc ?? '')
                            . '&rr=' . urlencode($invoice->receiver_rfc)
                            . '&tt=' . number_format($total, 6, '.', '')
                            . '&fe=' . substr($timbre['sello_cfd'], strlen($timbre['sello_cfd']) - 8);
                    @endphp
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode($qrUrl) }}&margin=2"
                         alt="Código QR SAT"
                         style="width:28mm; height:28mm;">
                @else
                    <div class="qr-placeholder">
                        QR<br>disponible<br>al timbrar
                    </div>
                @endif
            </div>

            <div class="seals-stack">
                <div class="seal-item">
                    <div class="seal-item-header">Sello digital del emisor</div>
                    <div class="seal-item-body">{{ $timbre['sello_cfd'] !== '—' ? $timbre['sello_cfd'] : 'Disponible después del timbrado ante el SAT.' }}</div>
                </div>
                <div class="seal-item">
                    <div class="seal-item-header">Sello digital del SAT</div>
                    <div class="seal-item-body">{{ $timbre['sello_sat'] !== '—' ? $timbre['sello_sat'] : 'Disponible después del timbrado ante el SAT.' }}</div>
                </div>
                <div class="seal-item">
                    <div class="seal-item-header">Cadena original del complemento de certificación digital</div>
                    <div class="seal-item-body">
                        @if ($timbre['uuid'] !== '—')
                            ||{{ $timbre['uuid'] }}|{{ $timbre['fecha_timbrado'] }}|{{ $timbre['rfc_prov_certif'] }}|{{ $timbre['no_certificado_sat'] }}||
                        @else
                            Disponible después del timbrado ante el SAT.
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ════════════════════════════════════════
             FOOTER
             ════════════════════════════════════════ --}}
        <div class="footer">
            <p>
                Este documento es una <strong>representación impresa de un CFDI</strong> (Comprobante Fiscal Digital por Internet) versión 4.0.
            </p>
            <p>
                {{ $invoice->fiscalProfile?->razon_social ?? 'Contribuyente' }} &middot;
                RFC {{ $invoice->fiscalProfile?->rfc ?? '—' }} &middot;
                Régimen fiscal {{ $invoice->fiscalProfile?->regimen_fiscal ?? '—' }}
            </p>
            <p style="margin-top:0.8mm;">
                Efectos fiscales al pago &middot; Este comprobante ampara la(s) operación(es) descrita(s) en los conceptos anteriores.
            </p>
        </div>

    </div>
</body>
</html>
