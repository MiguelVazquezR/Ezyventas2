<?php

namespace App\Services;

use App\Enums\TemplateType;
use App\Enums\TransactionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment; // Importamos el modelo Payment
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PrintEncoderService
{
    /**
     * Actúa como un enrutador para llamar al codificador correcto según el tipo de plantilla y la fuente de datos.
     */
    public static function encode(PrintTemplate $template, $dataSource, array $options = []): array
    {
        // 1. Ticket de Venta / Orden de Servicio / CLIENTE
        if (
            $template->type === TemplateType::SALE_TICKET &&
            ($dataSource instanceof Transaction || $dataSource instanceof ServiceOrder || $dataSource instanceof Customer)
        ) {
            return self::encodeEscPos($template, $dataSource, $options);
        }

        // 2. Etiqueta (Producto / OS)
        if ($template->type === TemplateType::LABEL && ($dataSource instanceof Product || $dataSource instanceof ServiceOrder)) {
            return self::encodeTspl($template, $dataSource, $options);
        }

        return [];
    }

    /**
     * Codifica una plantilla de Etiqueta (TSPL)
     */
    private static function encodeTspl(PrintTemplate $template, $dataSource, array $options = []): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $tspl = "SIZE {$config['width']} mm,{$config['height']} mm\n";
        $tspl .= "GAP {$config['gap']} mm,0 mm\n";
        $tspl .= "DENSITY 7\n";
        $tspl .= "SPEED 4\n";
        $tspl .= "DIRECTION 0\n";

        $dotsPerMm = ($config['dpi'] ?? 203) / 25.4;

        $offsetX_mm = $options['offset_x'] ?? 0;
        $offsetY_mm = $options['offset_y'] ?? 0;

        if (is_numeric($offsetX_mm) && is_numeric($offsetY_mm) && ($offsetX_mm != 0 || $offsetY_mm != 0)) {
            $shiftX_dots = round($offsetX_mm * $dotsPerMm);
            $shiftY_dots = round($offsetY_mm * $dotsPerMm);
            $tspl .= "SHIFT {$shiftX_dots},{$shiftY_dots}\n";
        }
        $tspl .= "OFFSET 0\n";
        $tspl .= "CLS\n";

        foreach ($elements as $element) {
            $x = ($element['data']['x'] ?? 0) * $dotsPerMm;
            $y = ($element['data']['y'] ?? 0) * $dotsPerMm;
            $rotation = $element['data']['rotation'] ?? 0;

            switch ($element['type']) {
                case 'text':
                    $fontSize = $element['data']['font_size'];
                    $text = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $tspl .= "TEXT {$x},{$y},\"{$fontSize}\",{$rotation},1,1,\"{$text}\"\n";
                    break;
                case 'barcode':
                    $barcodeType = $element['data']['type'];
                    $height = $element['data']['height'];
                    $value = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $tspl .= "BARCODE {$x},{$y},\"{$barcodeType}\",{$height},1,{$rotation},2,2,\"{$value}\"\n";
                    break;
                case 'qr':
                    $magnification = $element['data']['magnification'];
                    $value = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $tspl .= "QRCODE {$x},{$y},L,{$magnification},A,{$rotation},M2,\"{$value}\"\n";
                    break;
            }
        }

        $tspl .= "PRINT 1,1\n";
        return [['nombre' => 'EscribirTexto', 'argumentos' => [$tspl]]];
    }

    /**
     * Codifica una plantilla de Ticket (ESC/POS)
     */
    private static function encodeEscPos(PrintTemplate $template, $dataSource, array $options = []): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];

        if (!empty($options['open_drawer'])) {
            $operations[] = ['nombre' => 'AbrirCajon', 'argumentos' => []];
        }

        foreach ($elements as $element) {
            if (($element['type'] === 'image' || $element['type'] === 'local_image') && !empty($element['data']['url'])) {
                $operations[] = ['nombre' => 'DescargarImagenDeInternetEImprimir', 'argumentos' => [$element['data']['url'], $element['data']['width'] ?? null]];
            }
        }

        $rawText = self::buildEscPosRawText($elements, $config, $dataSource);

        if (!empty(trim($rawText))) {
            $operations[] = ['nombre' => 'TextoSegunPaginaDeCodigos', 'argumentos' => [0, $config['codepage'] ?? 'cp850', $rawText]];
        }

        return $operations;
    }

    private static function buildEscPosRawText(array $elements, array $config, $dataSource): string
    {
        $esc = "\x1B";
        $gs = "\x1D";
        $init = $esc . "@";
        $alignLeft = $esc . "a" . "\x00";
        $alignCenter = $esc . "a" . "\x01";
        $alignRight = $esc . "a" . "\x02";
        $boldOn = $esc . "E" . "\x01";
        $boldOff = $esc . "E" . "\x00";
        $cutPaper = $gs . "V" . "\x00" . "\x00";

        $widthChars = ($config['paperWidth'] ?? '80mm') === '80mm' ? 48 : 32;
        $fullText = $init;

        foreach ($elements as $element) {
            $align = $element['data']['align'] ?? 'left';
            $fullText .= match ($align) {
                'center' => $alignCenter,
                'right' => $alignRight,
                default => $alignLeft
            };

            switch ($element['type']) {
                case 'text':
                    $fullText .= self::replacePlaceholders($element['data']['text'], $dataSource) . "\n";
                    break;
                case 'separator':
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    break;
                case 'line_break':
                    $fullText .= "\n";
                    break;
                case 'barcode':
                    $barcodeData = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $height = $element['data']['height'] ?? 80;
                    $height = max(1, min(255, (int)$height));
                    $fullText .= $gs . 'h' . chr($height) . $gs . 'w' . chr(2) . $gs . 'k' . chr(73) . chr(strlen($barcodeData)) . $barcodeData . "\n";
                    break;
                case 'qr':
                    $qrData = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $size = $element['data']['size'] ?? 5;
                    $size = max(1, min(16, (int)$size));
                    $len = strlen($qrData) + 3;
                    $pL = chr($len % 256);
                    $pH = chr($len / 256);
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0) . $gs . '(k' . chr(3) . chr(0) . '1C' . chr($size) . $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48) . $gs . '(k' . $pL . $pH . '1P0' . $qrData . $gs . '(k' . chr(3) . chr(0) . '1Q0' . "\n";
                    break;
                case 'sales_table':
                    if (method_exists($dataSource, 'items') && $dataSource->items()->exists()) {
                        $fullText .= str_repeat('-', $widthChars) . "\n";
                        $header = str_pad("Cant", 5) . str_pad("Concepto", $widthChars - 15) . str_pad("Total", 10, ' ', STR_PAD_LEFT);
                        $fullText .= $boldOn . $header . $boldOff . "\n";
                        $fullText .= str_repeat('-', $widthChars) . "\n";
                        foreach ($dataSource->items as $item) {
                            $quantity = str_pad($item->quantity, 5);
                            $name = substr($item->description, 0, $widthChars - 15);
                            $namePadded = str_pad($name, $widthChars - 15);
                            $total = str_pad('$' . number_format($item->line_total, 2), 10, ' ', STR_PAD_LEFT);
                            $fullText .= $quantity . $namePadded . $total . "\n";
                        }
                        $fullText .= str_repeat('-', $widthChars) . "\n";
                    }
                    break;
            }
        }

        $fullText .= str_repeat("\n", $config['feedLines'] ?? 0);
        $fullText .= $cutPaper;
        return $fullText;
    }

    // --- MÉTODOS AUXILIARES ---

    private static function getNegocioReplacements(Subscription $subscription): array
    {
        return [
            '{{negocio.nombre}}' => $subscription->commercial_name,
            '{{negocio.razon_social}}' => $subscription->business_name,
            '{{negocio.direccion}}' => implode(', ', array_filter((array)($subscription->address ?? []))),
            '{{negocio.telefono}}' => $subscription->contact_phone,
        ];
    }

    private static function getSucursalReplacements(Branch $branch): array
    {
        return [
            '{{sucursal.nombre}}' => $branch->name,
            '{{sucursal.direccion}}' => implode(', ', array_filter((array)($branch->address ?? []))),
            '{{sucursal.telefono}}' => $branch->contact_phone,
        ];
    }

    private static function getClienteReplacements(?Customer $customer, ?ServiceOrder $serviceOrder = null): array
    {
        if ($customer) {
            return [
                '{{cliente.nombre}}' => $customer->name ?? 'Público en General',
                '{{cliente.telefono}}' => $customer->phone ?? '',
                '{{cliente.email}}' => $customer->email ?? '',
                '{{cliente.empresa}}' => $customer->company_name ?? '',
                '{{cliente.rfc}}' => $customer->tax_id ?? '',
                '{{cliente.direccion}}' => implode(', ', array_filter((array)($customer->address ?? []))),
            ];
        }
        if ($serviceOrder) {
            return [
                '{{cliente.nombre}}' => $serviceOrder->customer_name ?? 'N/A',
                '{{cliente.telefono}}' => $serviceOrder->customer_phone ?? '',
                '{{cliente.email}}' => $serviceOrder->customer_email ?? '',
                '{{cliente.empresa}}' => '',
                '{{cliente.rfc}}' => '',
                '{{cliente.direccion}}' => '',
            ];
        }
        return [
            '{{cliente.nombre}}' => 'Público en General',
            '{{cliente.telefono}}' => '',
            '{{cliente.email}}' => '',
            '{{cliente.empresa}}' => '',
            '{{cliente.rfc}}' => '',
            '{{cliente.direccion}}' => '',
        ];
    }

    private static function getVendedorReplacements(?User $user): array
    {
        return [
            '{{vendedor.nombre}}' => $user->name ?? 'N/A',
        ];
    }

    private static function getProductoReplacements(Product $product): array
    {
        return [
            '{{p.nombre}}' => $product->name,
            '{{p.precio}}' => number_format($product->selling_price, 2),
            '{{p.sku}}' => $product->sku,
            '{{p.codigo_barras}}' => $product->barcode ?? $product->sku,
        ];
    }

    private static function getTransactionReplacements(Transaction $transaction): array
    {
        $transaction->loadMissing('payments');
        $paymentMethods = $transaction->payments->pluck('payment_method.value')->unique()->map(fn($method) => ucfirst($method))->implode(', ');
        $totalPaid = $transaction->payments->sum('amount');
        $total = $transaction->subtotal - $transaction->total_discount + $transaction->total_tax;
        $remaining = $total - $totalPaid;

        return [
            '{{v.folio}}' => $transaction->folio,
            '{{v.fecha}}' => Carbon::parse($transaction->created_at)->format('d/m/Y'),
            '{{v.hora}}' => Carbon::parse($transaction->created_at)->format('H:i A'),
            '{{v.fecha_hora}}' => Carbon::parse($transaction->created_at)->format('d/m/Y H:i A'),
            '{{v.subtotal}}' => number_format($transaction->subtotal, 2),
            '{{v.descuentos}}' => number_format($transaction->total_discount, 2),
            '{{v.impuestos}}' => number_format($transaction->total_tax, 2),
            '{{v.total}}' => number_format($total, 2),
            '{{v.restante_por_pagar}}' => number_format($remaining, 2),
            '{{v.metodos_pago}}' => $paymentMethods,
            '{{v.total_pagado}}' => number_format($totalPaid, 2),
            '{{v.notas_venta}}' => $transaction->notes,
            '{{v.cambio}}' => number_format(max(0, $totalPaid - $total), 2),
            '{{v.fecha_vencimiento_apartado}}' => $transaction->layaway_expiration_date ? Carbon::parse($transaction->layaway_expiration_date)->format('d/m/Y') : 'No especificado',
        ];
    }

    private static function getServiceOrderReplacements(ServiceOrder $serviceOrder): array
    {
        $replacements = [
            '{{os.folio}}' => $serviceOrder->folio,
            '{{os.fecha_recepcion}}' => Carbon::parse($serviceOrder->received_at)->format('d/m/Y'),
            '{{os.hora_recepcion}}' => Carbon::parse($serviceOrder->received_at)->format('H:i A'),
            '{{os.fecha_hora_recepcion}}' => Carbon::parse($serviceOrder->received_at)->format('d/m/Y H:i A'),
            '{{os.subtotal}}' => number_format($serviceOrder->subtotal, 2),
            '{{os.descuento}}' => number_format($serviceOrder->discount_amount, 2),
            '{{os.total}}' => number_format($serviceOrder->final_total, 2),
            '{{os.problemas_reportados}}' => $serviceOrder->reported_problems,
            '{{os.item_description}}' => $serviceOrder->item_description,
            '{{os.diagnostico}}' => $serviceOrder->diagnosis ?? '',
            '{{os.fecha_promesa}}' => $serviceOrder->promised_at ? Carbon::parse($serviceOrder->promised_at)->format('d/m/Y') : '',
        ];

        if (!empty($serviceOrder->custom_fields)) {
            foreach ($serviceOrder->custom_fields as $key => $value) {
                $printValue = '';
                if (is_null($value)) {
                    $printValue = '';
                } elseif (is_bool($value)) {
                    $printValue = $value ? 'Si' : 'No';
                } elseif (is_array($value)) {
                    if (isset($value['value'])) {
                        $actualValue = $value['value'];
                        if (is_array($actualValue)) {
                            $printValue = implode(', ', $actualValue);
                        } else {
                            $printValue = (string) $actualValue;
                        }
                    } else {
                        $printValue = implode(', ', $value);
                    }
                } else {
                    $printValue = (string) $value;
                }
                $replacements["{{os.custom.{$key}}}"] = $printValue;
            }
        }
        return $replacements;
    }

    /**
     * --- NUEVO: Reemplazos para el Estado de Cuenta del Cliente con BLOQUES VERTICALES ---
     */
    private static function getCustomerAccountReplacements(Customer $customer): array
    {
        $saldoFormatted = $customer->balance < 0
            ? '-$' . number_format(abs($customer->balance), 2) . ' (Deuda)'
            : ($customer->balance > 0 ? '$' . number_format($customer->balance, 2) . ' (A favor)' : '$0.00');

        $pendingSalesQuery = $customer->transactions()
            ->whereIn('status', [TransactionStatus::PENDING, TransactionStatus::ON_LAYAWAY]);

        $pendingSalesCount = $pendingSalesQuery->count();
        $pendingSales = $pendingSalesQuery->get();

        // --- 1. Generar Bloque del Último Pago ---
        // Buscamos el último pago usando la relación correcta (Payment) a través de las transacciones del cliente
        // O más eficientemente, consultando la tabla payments directamente filtrando por transacciones del cliente.
        $lastPaymentInfo = "Sin pagos registrados.";

        $lastPayment = Payment::whereHas('transaction', function ($q) use ($customer) {
            $q->where('customer_id', $customer->id);
        })
            ->latest('created_at') // O 'payment_date' si prefieres
            ->first();

        if ($lastPayment) {
            $width = 32; // Ancho seguro para 58mm y 80mm
            $line = str_repeat('-', $width);

            // Acceso seguro al valor del Enum o string directo
            $methodValue = $lastPayment->payment_method;
            if ($methodValue instanceof \UnitEnum) { // Si es Enum de PHP 8.1+
                $methodValue = $methodValue->value;
            }
            $method = ucfirst($methodValue ?? 'Desconocido');

            $date = Carbon::parse($lastPayment->created_at)->format('d/m/Y H:i');
            $amount = '$' . number_format($lastPayment->amount, 2);

            // Formato de bloque vertical
            $lastPaymentInfo =
                "$line\n"
                . "ULTIMO PAGO REGISTRADO\n"
                . "$line\n"
                . "Fecha:  $date\n"
                . "Metodo: $method\n"
                . "Monto:  $amount\n"
                . "$line";
        }

        // --- 2. Generar Bloque de Ventas Pendientes ---
        $pendingSalesInfo = "Sin ventas pendientes.";

        if ($pendingSalesCount > 0) {
            $width = 32;
            $line = str_repeat('-', $width);
            $separator = str_repeat('.', $width); // Separador más ligero entre items

            $blocks = "";
            foreach ($pendingSales as $index => $sale) {
                $paid = $sale->payments()->sum('amount');
                $pending = $sale->total - $paid;

                if ($pending <= 0.01) continue;

                $folio = $sale->folio ?? $sale->id;
                $date = Carbon::parse($sale->created_at)->format('d/m/Y H:i');
                $totalStr = '$' . number_format($sale->total, 2);
                $pendingStr = '$' . number_format($pending, 2);

                // Bloque para cada venta
                $blocks .= "Folio: #$folio\n";
                $blocks .= "Fecha: $date\n";
                $blocks .= "Total: $totalStr\n";
                $blocks .= "Debe:  $pendingStr\n";

                // Añadir separador si no es el último
                if ($index < $pendingSalesCount - 1) {
                    $blocks .= "$separator\n";
                }
            }

            $pendingSalesInfo =
                "$line\n"
                . "VENTAS PENDIENTES\n"
                . "$line\n"
                . $blocks
                . "$line";
        }

        return [
            '{{c.saldo_actual}}' => $saldoFormatted,
            '{{c.credito_disponible}}' => number_format($customer->available_credit, 2),
            '{{c.limite_credito}}' => number_format($customer->credit_limit, 2),
            '{{c.conteo_ventas_pendientes}}' => $pendingSalesCount,
            '{{c.total_deuda}}' => number_format(abs(min(0, $customer->balance)), 2),

            // Variables actualizadas con formato bloque vertical
            '{{c.tabla_ultimo_pago}}' => $lastPaymentInfo,
            '{{c.tabla_ventas_pendientes}}' => $pendingSalesInfo,
        ];
    }

    private static function replacePlaceholders(string $text, $dataSource): string
    {
        $replacements = [];

        if ($dataSource instanceof Product) {
            $dataSource->loadMissing(['branch.subscription']);
            $replacements += self::getProductoReplacements($dataSource);
            $replacements += self::getNegocioReplacements($dataSource->branch->subscription);
            $replacements += self::getSucursalReplacements($dataSource->branch);
        } elseif ($dataSource instanceof Transaction) {
            $dataSource->loadMissing(['customer', 'branch.subscription', 'user', 'payments']);
            $replacements += self::getTransactionReplacements($dataSource);
            $replacements += self::getNegocioReplacements($dataSource->branch->subscription);
            $replacements += self::getSucursalReplacements($dataSource->branch);
            $replacements += self::getClienteReplacements($dataSource->customer);
            $replacements += self::getVendedorReplacements($dataSource->user);
        } elseif ($dataSource instanceof ServiceOrder) {
            $dataSource->loadMissing(['branch.subscription', 'user', 'customer', 'transaction.payments']);

            $replacements += self::getServiceOrderReplacements($dataSource);
            $replacements += self::getNegocioReplacements($dataSource->branch->subscription);
            $replacements += self::getSucursalReplacements($dataSource->branch);
            $replacements += self::getClienteReplacements($dataSource->customer, $dataSource);
            $replacements += self::getVendedorReplacements($dataSource->user);

            if ($dataSource->transaction) {
                $replacements += self::getTransactionReplacements($dataSource->transaction);
            }
        } elseif ($dataSource instanceof Customer) {
            $dataSource->loadMissing(['branch.subscription']);

            $replacements += self::getCustomerAccountReplacements($dataSource);
            $replacements += self::getClienteReplacements($dataSource);

            if ($dataSource->branch) {
                $replacements += self::getNegocioReplacements($dataSource->branch->subscription);
                $replacements += self::getSucursalReplacements($dataSource->branch);
            } else {
                $replacements['{{sucursal.nombre}}'] = 'Global';
            }

            $replacements += self::getVendedorReplacements(auth()->user());
        }

        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        $text = preg_replace('/{{os\.custom\.(.*?)}}/', '', $text);
        $text = preg_replace('/{{v\.(.*?)}}/', '', $text);
        $text = preg_replace('/{{c\.(.*?)}}/', '', $text);

        return $text;
    }
}
