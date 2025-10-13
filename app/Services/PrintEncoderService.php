<?php

namespace App\Services;

use App\Enums\TemplateType;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PrintEncoderService
{
    /**
     * Actúa como un enrutador para llamar al codificador correcto según el tipo de plantilla y la fuente de datos.
     * @param PrintTemplate $template
     * @param mixed $dataSource
     * @param array $options Contiene opciones adicionales como los desfases de impresión.
     * @return array
     */
    public static function encode(PrintTemplate $template, $dataSource, array $options = []): array
    {
        // Un ticket puede ser para una Venta o para una Orden de Servicio
        if ($template->type === TemplateType::SALE_TICKET && ($dataSource instanceof Transaction || $dataSource instanceof ServiceOrder)) {
            // Las opciones no son necesarias para ESC/POS por ahora
            return self::encodeEscPos($template, $dataSource);
        }

        // Una etiqueta puede ser para un Producto o una Orden de Servicio
        if ($template->type === TemplateType::LABEL && ($dataSource instanceof Product || $dataSource instanceof ServiceOrder)) {
            // Pasar las opciones al codificador TSPL
            return self::encodeTspl($template, $dataSource, $options);
        }

        return []; // Devuelve vacío si el tipo no es compatible
    }

    /**
     * Codifica una plantilla de Etiqueta a un array de operaciones JSON (con comandos TSPL crudos).
     * @param PrintTemplate $template
     * @param mixed $dataSource
     * @param array $options
     * @return array
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

        $dotsPerMm = $config['dpi'] / 25.4;

        // --- INICIO: Lógica de Calibración ---
        $offsetX_mm = $options['offset_x'] ?? 0;
        $offsetY_mm = $options['offset_y'] ?? 0;

        // Solo añadir el comando SHIFT si alguno de los desfases es diferente de cero.
        if (is_numeric($offsetX_mm) && is_numeric($offsetY_mm) && ($offsetX_mm != 0 || $offsetY_mm != 0)) {
            $shiftX_dots = round($offsetX_mm * $dotsPerMm);
            $shiftY_dots = round($offsetY_mm * $dotsPerMm);
            $tspl .= "SHIFT {$shiftX_dots},{$shiftY_dots}\n";
        }
        $tspl .= "OFFSET 0\n";
        // --- FIN: Lógica de Calibración ---

        // CLS (limpiar búfer de imagen) debe llamarse DESPUÉS de SHIFT para evitar resetear el sistema de coordenadas.
        $tspl .= "CLS\n";

        foreach ($elements as $element) {
            $x = ($element['data']['x'] ?? 0) * $dotsPerMm;
            $y = ($element['data']['y'] ?? 0) * $dotsPerMm;
            $rotation = $element['data']['rotation'] ?? 0;

            switch ($element['type']) {
                case 'text':
                    //TEXT X, Y, "font", rotation, x-multiplication, y-multiplication, "content"
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
     * Codifica una plantilla de Ticket a un array de operaciones JSON para el plugin.
     */
    private static function encodeEscPos(PrintTemplate $template, $dataSource): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];
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
                    $fullText .= $gs . 'h' . chr(80) . $gs . 'w' . chr(2) . $gs . 'k' . chr(73) . chr(strlen($barcodeData)) . $barcodeData . "\n";
                    break;
                case 'qr':
                    $qrData = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $len = strlen($qrData) + 3;
                    $pL = chr($len % 256);
                    $pH = chr($len / 256);
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0) . $gs . '(k' . chr(3) . chr(0) . '1C' . chr(5) . $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48) . $gs . '(k' . $pL . $pH . '1P0' . $qrData . $gs . '(k' . chr(3) . chr(0) . '1Q0' . "\n";
                    break;
                case 'sales_table':
                    if ($dataSource->items()->exists()) {
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

    private static function replacePlaceholders(string $text, $dataSource): string
    {
        $replacements = [];

        if ($dataSource instanceof Product) {
            $dataSource->loadMissing(['branch.subscription']);
            $replacements = [
                '{{p.nombre}}' => $dataSource->name,
                '{{p.precio}}' => number_format($dataSource->selling_price, 2),
                '{{p.sku}}' => $dataSource->sku,
                '{{negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{sucursal.nombre}}' => $dataSource->branch->name,
            ];
        } elseif ($dataSource instanceof Transaction) {
            $dataSource->loadMissing(['customer', 'branch.subscription', 'user', 'payments']);
            $paymentMethods = $dataSource->payments->pluck('payment_method.value')->unique()->map(fn($method) => ucfirst($method))->implode(', ');
            $replacements = [
                '{{v.folio}}' => $dataSource->folio,
                '{{v.fecha}}' => Carbon::parse($dataSource->created_at)->format('d/m/Y'),
                '{{v.hora}}' => Carbon::parse($dataSource->created_at)->format('H:i A'),
                '{{v.fecha_hora}}' => Carbon::parse($dataSource->created_at)->format('d/m/Y H:i A'),
                '{{v.subtotal}}' => number_format($dataSource->subtotal, 2),
                '{{v.descuentos}}' => number_format($dataSource->total_discount, 2),
                '{{v.impuestos}}' => number_format($dataSource->total_tax, 2),
                '{{v.total}}' => number_format($dataSource->subtotal - $dataSource->total_discount + $dataSource->total_tax, 2),
                '{{v.metodos_pago}}' => $paymentMethods,
                '{{v.notas_venta}}' => $dataSource->notes,
                '{{v.cliente.nombre}}' => $dataSource->customer->name ?? 'Público en General',
                '{{v.cliente.telefono}}' => $dataSource->customer->phone ?? '',
                '{{v.cliente.email}}' => $dataSource->customer->email ?? '',
                '{{v.cliente.empresa}}' => $dataSource->customer->company_name ?? '',
                '{{v.negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{v.negocio.razon_social}}' => $dataSource->branch->subscription->business_name,
                '{{v.negocio.direccion}}' => implode(', ', array_filter((array)($dataSource->branch->subscription->address ?? []))),
                '{{v.negocio.telefono}}' => $dataSource->branch->subscription->contact_phone,
                '{{v.sucursal.nombre}}' => $dataSource->branch->name,
                '{{v.sucursal.direccion}}' => implode(', ', array_filter((array)($dataSource->branch->address ?? []))),
                '{{v.sucursal.telefono}}' => $dataSource->branch->contact_phone,
                '{{v.vendedor.nombre}}' => $dataSource->user->name,
            ];
        } elseif ($dataSource instanceof ServiceOrder) {
            $dataSource->loadMissing(['branch.subscription', 'user']);
            $subtotal = $dataSource->items->sum('line_total');
            $discount = $subtotal - $dataSource->final_total;

            $replacements = [
                '{{os.folio}}' => $dataSource->folio,
                '{{os.fecha_recepcion}}' => Carbon::parse($dataSource->received_at)->format('d/m/Y'),
                '{{os.hora_recepcion}}' => Carbon::parse($dataSource->received_at)->format('H:i A'),
                '{{os.fecha_hora_recepcion}}' => Carbon::parse($dataSource->received_at)->format('d/m/Y H:i A'),
                '{{os.subtotal}}' => number_format($subtotal, 2),
                '{{os.descuentos}}' => number_format($discount, 2),
                '{{os.total}}' => number_format($dataSource->final_total, 2),
                '{{os.cliente.nombre}}' => $dataSource->customer_name ?? ($dataSource->customer->name ?? 'N/A'),
                '{{os.cliente.telefono}}' => $dataSource->customer_phone ?? ($dataSource->customer->phone ?? ''),
                '{{os.cliente.email}}' => $dataSource->customer_email ?? ($dataSource->customer->email ?? ''),
                '{{os.negocio.nombre}}' => $dataSource->branch->subscription->commercial_name,
                '{{os.sucursal.nombre}}' => $dataSource->branch->name,
                '{{os.vendedor.nombre}}' => $dataSource->user->name,
                '{{os.problemas_reportados}}' => $dataSource->reported_problems,
                '{{os.item_description}}' => $dataSource->item_description,
            ];

            // --- Lógica para campos personalizados ---
            if (!empty($dataSource->custom_fields)) {
                foreach ($dataSource->custom_fields as $key => $fieldData) {
                    // Nos aseguramos de que el campo tenga un valor que mostrar
                    if (isset($fieldData['value'])) {
                        $printValue = $fieldData['value'];
                        // Si el valor es un array (como en el caso de 'pattern'), lo convertimos a texto
                        if (is_array($printValue)) {
                            $printValue = implode(', ', $printValue);
                        }
                        $replacements["{{os.custom.{$key}}}"] = $printValue ?? '';
                    }
                }
            }
        }

        // Reemplazar cualquier placeholder de campo personalizado que no tuviera valor para evitar que se imprima la variable
        $text = preg_replace('/{{os\.custom\.(.*?)}}/', '', $text);
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
