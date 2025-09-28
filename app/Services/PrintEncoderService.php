<?php

namespace App\Services;

use App\Enums\TemplateType;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Carbon\Carbon;

class PrintEncoderService
{
    /**
     * Actúa como un enrutador para llamar al codificador correcto según el tipo de plantilla y la fuente de datos.
     */
    public static function encode(PrintTemplate $template, $dataSource): array
    {
        if ($template->type === TemplateType::SALE_TICKET && $dataSource instanceof Transaction) {
            return self::encodeEscPos($template, $dataSource);
        }

        // Se expande la lógica para que las etiquetas puedan usar tanto datos de Producto como de Transacción.
        if ($template->type === TemplateType::LABEL && ($dataSource instanceof Product || $dataSource instanceof Transaction || $dataSource instanceof ServiceOrder)) {
            return self::encodeTspl($template, $dataSource);
        }

        return []; // Devuelve vacío si el tipo no es compatible
    }

    /**
     * Codifica una plantilla de Etiqueta a un array de operaciones JSON (con comandos TSPL crudos).
     */
    private static function encodeTspl(PrintTemplate $template, $dataSource): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $tspl = "SIZE {$config['width']} mm,{$config['height']} mm\n";
        $tspl .= "GAP {$config['gap']} mm,0 mm\n";
        $tspl .= "DENSITY 15\n";
        $tspl .= "SPEED 4\n";
        $tspl .= "DIRECTION 1\n";
        $tspl .= "CLS\n";

        $dotsPerMm = $config['dpi'] / 25.4;

        foreach ($elements as $element) {
            $x = ($element['data']['x'] ?? 0) * $dotsPerMm;
            $y = ($element['data']['y'] ?? 0) * $dotsPerMm;
            $rotation = $element['data']['rotation'] ?? 0;

            switch ($element['type']) {
                case 'text':
                    $fontSize = $element['data']['font_size'];
                    $text = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $tspl .= "TEXT {$x},{$y},\"2\",{$rotation},{$fontSize},{$fontSize},\"{$text}\"\n";
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
     * Codifica una plantilla de Ticket de Venta a un array de operaciones JSON para el plugin.
     */
    private static function encodeEscPos(PrintTemplate $template, Transaction $transaction): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];

        foreach ($elements as $element) {
            if (($element['type'] === 'image' || $element['type'] === 'local_image') && !empty($element['data']['url'])) {
                $operations[] = ['nombre' => 'DescargarImagenDeInternetEImprimir', 'argumentos' => [$element['data']['url'], $element['data']['width'] ?? null]];
            }
        }

        $rawText = self::buildEscPosRawText($elements, $config, $transaction);

        if (!empty(trim($rawText))) {
            $operations[] = ['nombre' => 'TextoSegunPaginaDeCodigos', 'argumentos' => [0, $config['codepage'] ?? 'cp850', $rawText]];
        }

        return $operations;
    }

    private static function buildEscPosRawText(array $elements, array $config, Transaction $transaction): string
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
                    $fullText .= self::replacePlaceholders($element['data']['text'], $transaction) . "\n";
                    break;
                case 'separator':
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    break;
                case 'line_break':
                    $fullText .= "\n";
                    break;
                case 'barcode':
                    $barcodeData = self::replacePlaceholders($element['data']['value'], $transaction);
                    $fullText .= $gs . 'h' . chr(80) . $gs . 'w' . chr(2) . $gs . 'k' . chr(73) . chr(strlen($barcodeData)) . $barcodeData . "\n";
                    break;
                case 'qr':
                    $qrData = self::replacePlaceholders($element['data']['value'], $transaction);
                    $len = strlen($qrData) + 3;
                    $pL = chr($len % 256);
                    $pH = chr($len / 256);
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0) . $gs . '(k' . chr(3) . chr(0) . '1C' . chr(5) . $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48) . $gs . '(k' . $pL . $pH . '1P0' . $qrData . $gs . '(k' . chr(3) . chr(0) . '1Q0' . "\n";
                    break;
                case 'sales_table':
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    $header = str_pad("Cant", 5) . str_pad("Producto", $widthChars - 15) . str_pad("Total", 10, ' ', STR_PAD_LEFT);
                    $fullText .= $boldOn . $header . $boldOff . "\n";
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    foreach ($transaction->items as $item) {
                        $quantity = str_pad($item->quantity, 5);
                        $name = substr($item->description, 0, $widthChars - 15);
                        $namePadded = str_pad($name, $widthChars - 15);
                        $total = str_pad('$' . number_format($item->line_total, 2), 10, ' ', STR_PAD_LEFT);
                        $fullText .= $quantity . $namePadded . $total . "\n";
                    }
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    break;
            }
        }

        $fullText .= str_repeat("\n", $config['feedLines'] ?? 0);
        $fullText .= $cutPaper;
        return $fullText;
    }

    private static function replacePlaceholders(string $text, $dataSource): string
    {
        $replacements = [];

        if ($dataSource instanceof Product) {
            $dataSource->loadMissing(['branch.subscription']);
            $replacements = [
                '{{producto.nombre}}' => $dataSource->name,
                '{{producto.precio}}' => number_format($dataSource->selling_price, 2),
                '{{producto.sku}}' => $dataSource->sku,
                '{{negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{sucursal.nombre}}' => $dataSource->branch->name,
            ];
        } elseif ($dataSource instanceof Transaction) {
            $dataSource->loadMissing(['customer', 'branch.subscription', 'user', 'payments']);
            $paymentMethods = $dataSource->payments->pluck('payment_method.value')->unique()->map(fn($method) => ucfirst($method))->implode(', ');
            $replacements = [
                '{{folio}}' => $dataSource->folio,
                '{{fecha}}' => Carbon::parse($dataSource->created_at)->format('d/m/Y'),
                '{{hora}}' => Carbon::parse($dataSource->created_at)->format('H:i A'),
                '{{fecha_hora}}' => Carbon::parse($dataSource->created_at)->format('d/m/Y H:i A'),
                '{{subtotal}}' => number_format($dataSource->subtotal, 2),
                '{{descuentos}}' => number_format($dataSource->total_discount, 2),
                '{{impuestos}}' => number_format($dataSource->total_tax, 2),
                '{{total}}' => number_format($dataSource->subtotal - $dataSource->total_discount + $dataSource->total_tax, 2),
                '{{metodos_pago}}' => $paymentMethods,
                '{{notas_venta}}' => $dataSource->notes,
                '{{cliente.nombre}}' => $dataSource->customer->name ?? 'Público en General',
                '{{cliente.telefono}}' => $dataSource->customer->phone ?? '',
                '{{cliente.email}}' => $dataSource->customer->email ?? '',
                '{{cliente.empresa}}' => $dataSource->customer->company_name ?? '',
                '{{negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{negocio.razon_social}}' => $dataSource->branch->subscription->business_name,
                '{{negocio.direccion}}' => implode(', ', array_filter((array)($dataSource->branch->subscription->address ?? []))),
                '{{negocio.telefono}}' => $dataSource->branch->subscription->contact_phone,
                '{{sucursal.nombre}}' => $dataSource->branch->name,
                '{{sucursal.direccion}}' => implode(', ', array_filter((array)($dataSource->branch->address ?? []))),
                '{{sucursal.telefono}}' => $dataSource->branch->contact_phone,
                '{{vendedor.nombre}}' => $dataSource->user->name,
            ];
        } elseif ($dataSource instanceof ServiceOrder) {
            $dataSource->loadMissing(['customer', 'branch.subscription']);
            $replacements = [
                '{{orden.folio}}' => $dataSource->folio,
                '{{orden.fecha_recepcion}}' => Carbon::parse($dataSource->received_at)->format('d/m/Y'),
                '{{orden.hora_recepcion}}' => Carbon::parse($dataSource->received_at)->format('H:i A'),
                '{{orden.fecha_hora_recepcion}}' => Carbon::parse($dataSource->received_at)->format('d/m/Y H:i A'),
                '{{orden.cliente.nombre}}' => $dataSource->customer_name ?? '',
                '{{orden.cliente.telefono}}' => $dataSource->customer_phone ?? '',
                '{{orden.problemas_reportados}}' => $dataSource->reported_problems,
                '{{orden.item_description}}' => $dataSource->item_description,
                '{{negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{sucursal.nombre}}' => $dataSource->branch->name,
            ];
        }

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
