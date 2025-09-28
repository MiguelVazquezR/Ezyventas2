<?php

namespace App\Services;

use App\Models\PrintTemplate;
use App\Models\Transaction;
use Carbon\Carbon;

class EscPosEncoderService
{
    public static function encode(PrintTemplate $template, Transaction $transaction): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];
        $images = [];
        $rawText = self::buildRawText($elements, $config, $transaction);

        // 1. Agrega las imágenes al principio de las operaciones
        foreach ($elements as $element) {
            if (($element['type'] === 'image' || $element['type'] === 'local_image') && !empty($element['data']['url'])) {
                $operations[] = [
                    'nombre' => 'DescargarImagenDeInternetEImprimir',
                    'argumentos' => [$element['data']['url'], $element['data']['width'] ?? null]
                ];
            }
        }

        // 2. Agrega el texto con comandos crudos
        if (!empty(trim($rawText))) {
            $operations[] = [
                'nombre' => 'TextoSegunPaginaDeCodigos',
                'argumentos' => [0, $config['codepage'] ?? 'cp850', $rawText]
            ];
        }

        return $operations;
    }

    private static function buildRawText(array $elements, array $config, Transaction $transaction): string
    {
        $esc = "\x1B";
        $gs = "\x1D";

        // Comandos ESC/POS
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
                default => $alignLeft,
            };

            switch ($element['type']) {
                case 'text':
                    $fullText .= self::replacePlaceholders($element['data']['text'], $transaction) . "\n";
                    break;
                case 'separator':
                    $fullText .= str_repeat('-', $widthChars) . "\n";
                    break;
                case 'line_break': // Se añade el nuevo elemento
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
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0) . $gs . '(k' . chr(3) . chr(0) . '1C' . chr(5) . $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48) . $gs . '(k' . $pL . $pH . '1P0' . $qrData . $gs . '(k' . chr(3) . chr(0) . '1Q0';
                    $fullText .= "\n";
                    break;
                case 'sales_table':
                    // Se genera la tabla de productos con formato mejorado
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

        // Se aplican los saltos de línea finales y se corta el papel
        $fullText .= str_repeat("\n", $config['feedLines'] ?? 0);
        // $fullText .= $cutPaper;

        return $fullText;
    }

    /**
     * Reemplaza los placeholders en una cadena de texto con datos reales de la transacción.
     */
    private static function replacePlaceholders(string $text, Transaction $transaction): string
    {
        // Asegura que todas las relaciones necesarias estén cargadas para evitar consultas N+1
        $transaction->loadMissing(['customer', 'branch.subscription', 'user', 'payments']);

        $paymentMethods = $transaction->payments->pluck('payment_method.value')->unique()->map(fn($method) => ucfirst($method))->implode(', ');

        $replacements = [
            // Venta
            '{{folio}}' => $transaction->folio,
            '{{fecha}}' => Carbon::parse($transaction->created_at)->format('d/m/Y'),
            '{{hora}}' => Carbon::parse($transaction->created_at)->format('H:i A'),
            '{{fecha_hora}}' => Carbon::parse($transaction->created_at)->format('d/m/Y H:i A'),
            '{{subtotal}}' => number_format($transaction->subtotal, 2),
            '{{descuentos}}' => number_format($transaction->total_discount, 2),
            '{{impuestos}}' => number_format($transaction->total_tax, 2),
            '{{total}}' => number_format($transaction->subtotal - $transaction->total_discount + $transaction->total_tax, 2),
            '{{metodos_pago}}' => $paymentMethods,
            '{{notas_venta}}' => $transaction->notes,

            // Cliente
            '{{cliente.nombre}}' => $transaction->customer->name ?? 'Público en General',
            '{{cliente.telefono}}' => $transaction->customer->phone ?? '',
            '{{cliente.email}}' => $transaction->customer->email ?? '',
            '{{cliente.empresa}}' => $transaction->customer->company_name ?? '',

            // Negocio / Sucursal
            '{{negocio.nombre}}' => $transaction->branch->subscription->commercial_name,
            '{{negocio.razon_social}}' => $transaction->branch->subscription->business_name,
            '{{negocio.direccion}}' => implode(', ', array_filter((array)($transaction->branch->subscription->address ?? []))),
            '{{negocio.telefono}}' => $transaction->branch->subscription->contact_phone,
            '{{sucursal.nombre}}' => $transaction->branch->name,
            '{{sucursal.direccion}}' => implode(', ', array_filter((array)($transaction->branch->address ?? []))),
            '{{sucursal.telefono}}' => $transaction->branch->contact_phone,

            // Vendedor / Usuario
            '{{vendedor.nombre}}' => $transaction->user->name,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
