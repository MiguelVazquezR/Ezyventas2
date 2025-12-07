<?php

namespace App\Services;

use App\Enums\TemplateType;
use App\Models\Branch;
use App\Models\Customer;
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
     * @param PrintTemplate $template
     * @param mixed $dataSource
     * @param array $options Contiene opciones adicionales como los desfases de impresión o abrir cajón.
     * @return array
     */
    public static function encode(PrintTemplate $template, $dataSource, array $options = []): array
    {
        // Un ticket puede ser para una Venta o para una Orden de Servicio
        if ($template->type === TemplateType::SALE_TICKET && ($dataSource instanceof Transaction || $dataSource instanceof ServiceOrder)) {
            // AHORA PASAMOS $options TAMBIÉN AQUÍ
            return self::encodeEscPos($template, $dataSource, $options);
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

        $dotsPerMm = ($config['dpi'] ?? 203) / 25.4; // Default a 203 dpi si no está seteado

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
    private static function encodeEscPos(PrintTemplate $template, $dataSource, array $options = []): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];

        // --- LÓGICA DE APERTURA DE CAJÓN ---
        // Si la opción viene activada desde el Modal, agregamos el comando al principio.
        if (!empty($options['open_drawer'])) {
            $operations[] = ['nombre' => 'AbrirCajon', 'argumentos' => []];
        }
        // -----------------------------------

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
                    $height = $element['data']['height'] ?? 80; // Get height, default 80
                    $height = max(1, min(255, (int)$height));    // Clamp value
                    $fullText .= $gs . 'h' . chr($height) . $gs . 'w' . chr(2) . $gs . 'k' . chr(73) . chr(strlen($barcodeData)) . $barcodeData . "\n"; // Use $height
                    break;
                case 'qr':
                    $qrData = self::replacePlaceholders($element['data']['value'], $dataSource);
                    $size = $element['data']['size'] ?? 5; // Get size, default 5
                    $size = max(1, min(16, (int)$size));   // Clamp value
                    $len = strlen($qrData) + 3;
                    $pL = chr($len % 256);
                    $pH = chr($len / 256);
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0) . $gs . '(k' . chr(3) . chr(0) . '1C' . chr($size) . $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48) . $gs . '(k' . $pL . $pH . '1P0' . $qrData . $gs . '(k' . chr(3) . chr(0) . '1Q0' . "\n"; // Use $size
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

    // --- MÉTODOS AUXILIARES REFACTORIZADOS ---

    /**
     * Obtiene los reemplazos para las variables del Negocio (Suscripción).
     */
    private static function getNegocioReplacements(Subscription $subscription): array
    {
        return [
            '{{negocio.nombre}}' => $subscription->commercial_name,
            '{{negocio.razon_social}}' => $subscription->business_name,
            '{{negocio.direccion}}' => implode(', ', array_filter((array)($subscription->address ?? []))),
            '{{negocio.telefono}}' => $subscription->contact_phone,
        ];
    }

    /**
     * Obtiene los reemplazos para las variables de la Sucursal.
     */
    private static function getSucursalReplacements(Branch $branch): array
    {
        return [
            '{{sucursal.nombre}}' => $branch->name,
            '{{sucursal.direccion}}' => implode(', ', array_filter((array)($branch->address ?? []))),
            '{{sucursal.telefono}}' => $branch->contact_phone,
        ];
    }

    /**
     * Obtiene los reemplazos para las variables del Cliente.
     * Usa la ServiceOrder como fallback para datos de cliente si no existe un Customer.
     */
    private static function getClienteReplacements(?Customer $customer, ?ServiceOrder $serviceOrder = null): array
    {
        if ($customer) {
            return [
                '{{cliente.nombre}}' => $customer->name ?? 'Público en General',
                '{{cliente.telefono}}' => $customer->phone ?? '',
                '{{cliente.email}}' => $customer->email ?? '',
                '{{cliente.empresa}}' => $customer->company_name ?? '',
            ];
        }
        if ($serviceOrder) {
            return [
                '{{cliente.nombre}}' => $serviceOrder->customer_name ?? 'N/A',
                '{{cliente.telefono}}' => $serviceOrder->customer_phone ?? '',
                '{{cliente.email}}' => $serviceOrder->customer_email ?? '',
                '{{cliente.empresa}}' => '', // ServiceOrder no tiene "empresa"
            ];
        }
        return [
            '{{cliente.nombre}}' => 'Público en General',
            '{{cliente.telefono}}' => '',
            '{{cliente.email}}' => '',
            '{{cliente.empresa}}' => '',
        ];
    }

    /**
     * Obtiene los reemplazos para las variables del Vendedor (Usuario).
     */
    private static function getVendedorReplacements(User $user): array
    {
        return [
            '{{vendedor.nombre}}' => $user->name,
        ];
    }

    /**
     * Obtiene los reemplazos para las variables de Producto (para etiquetas).
     */
    private static function getProductoReplacements(Product $product): array
    {
        return [
            '{{p.nombre}}' => $product->name,
            '{{p.precio}}' => number_format($product->selling_price, 2),
            '{{p.sku}}' => $product->sku,
        ];
    }

    /**
     * Obtiene los reemplazos para las variables de Transacción (Venta).
     */
    private static function getTransactionReplacements(Transaction $transaction): array
    {
        $transaction->loadMissing('payments');
        $paymentMethods = $transaction->payments->pluck('payment_method.value')->unique()->map(fn($method) => ucfirst($method))->implode(', ');
        $totalPaid = $transaction->payments->sum('amount');

        return [
            '{{v.folio}}' => $transaction->folio,
            '{{v.fecha}}' => Carbon::parse($transaction->created_at)->format('d/m/Y'),
            '{{v.hora}}' => Carbon::parse($transaction->created_at)->format('H:i A'),
            '{{v.fecha_hora}}' => Carbon::parse($transaction->created_at)->format('d/m/Y H:i A'),
            '{{v.subtotal}}' => number_format($transaction->subtotal, 2),
            '{{v.descuentos}}' => number_format($transaction->total_discount, 2),
            '{{v.impuestos}}' => number_format($transaction->total_tax, 2),
            '{{v.total}}' => number_format($transaction->subtotal - $transaction->total_discount + $transaction->total_tax, 2),
            '{{v.metodos_pago}}' => $paymentMethods,
            '{{v.total_pagado}}' => number_format($totalPaid, 2),
            '{{v.notas_venta}}' => $transaction->notes,
        ];
    }

    /**
     * Obtiene los reemplazos para las variables de Orden de Servicio.
     */
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
        ];

        // --- Lógica mejorada para campos personalizados ---
        if (!empty($serviceOrder->custom_fields)) {
            foreach ($serviceOrder->custom_fields as $key => $value) {
                $printValue = ''; // Valor por defecto
                
                if (is_null($value)) {
                    $printValue = ''; // Imprime nada para valores nulos
                } elseif (is_bool($value)) {
                    $printValue = $value ? 'Si' : 'No'; // Convierte booleano a Si/No
                } elseif (is_array($value)) {
                    // Si es un objeto complejo (como 'desbloqueo') que tiene una clave 'value'
                    if (isset($value['value'])) {
                        $actualValue = $value['value'];
                        // Si el valor interno también es un array (como en 'pattern')
                        if (is_array($actualValue)) {
                            $printValue = implode(', ', $actualValue);
                        } else {
                            $printValue = (string) $actualValue;
                        }
                    } else {
                        // Si es un array simple (p. ej. de checkboxes o accesorios)
                        $printValue = implode(', ', $value);
                    }
                } else {
                    // Para valores simples como texto, números, etc.
                    $printValue = (string) $value;
                }

                $replacements["{{os.custom.{$key}}}"] = $printValue;
            }
        }
        return $replacements;
    }

    /**
     * Método principal refactorizado para construir el array de reemplazos.
     */
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

            // Si la OS tiene una transacción asociada, carga también las variables de transacción (v.*)
            if ($dataSource->transaction) {
                $replacements += self::getTransactionReplacements($dataSource->transaction);
            }
        }

        // Reemplaza los placeholders y luego limpia los que no se encontraron
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);

        // Limpia cualquier placeholder de {{os.custom.*}} que no tuvo un valor
        // y cualquier variable 'v.*' si no había transacción (ej. en una OS sin pagar)
        $text = preg_replace('/{{os\.custom\.(.*?)}}/', '', $text);
        $text = preg_replace('/{{v\.(.*?)}}/', '', $text);
        
        return $text;
    }
}