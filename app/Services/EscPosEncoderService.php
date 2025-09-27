<?php

namespace App\Services;

use App\Models\PrintTemplate;
use App\Models\Transaction;

class EscPosEncoderService
{
    /**
     * Traduce una plantilla visual a un array de operaciones JSON para el plugin de impresión.
     */
    public static functionencode(PrintTemplate $template, Transaction $transaction): array
    {
        $config = $template->content['config'] ?? [];
        $elements = $template->content['elements'] ?? [];

        $operations = [];
        $images = [];

        // 1. Recolectar todas las imágenes primero
        foreach ($elements as $element) {
            if (($element['type'] === 'image' || $element['type'] === 'local_image') && !empty($element['data']['url'])) {
                $images[] = [
                    'nombre' => 'DescargarImagenDeInternetEImprimir',
                    'argumentos' => [$element['data']['url'], $element['data']['width'] ?? null]
                ];
            }
        }

        // 2. Construir la cadena de texto con comandos ESC/POS
        $rawText = self::buildRawText($elements, $config, $transaction);

        // 3. Ensamblar el array final de operaciones
        if (!empty($images)) {
            $operations = array_merge($operations, $images);
        }
        if (!empty($rawText)) {
            $operations[] = [
                'nombre' => 'TextoSegunPaginaDeCodigos',
                'argumentos' => [0, $config['codepage'] ?? 'cp850', $rawText]
            ];
        }
        if (($config['feedLines'] ?? 0) > 0) {
            $operations[] = ['nombre' => 'Feed', 'argumentos' => [$config['feedLines']]];
        }

        return $operations;
    }

    /**
     * Construye la cadena de texto cruda con comandos ESC/POS embebidos.
     */
    private static function buildRawText(array $elements, array $config, Transaction $transaction): string
    {
        $esc = "\x1B";
        $gs = "\x1D";
        $init = $esc . "@";
        $alignLeft = $esc . "a" . "\x00";
        $alignCenter = $esc . "a" . "\x01";
        $alignRight = $esc . "a" . "\x02";
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
                case 'barcode':
                    $barcodeData = self::replacePlaceholders($element['data']['value'], $transaction);
                    $fullText .= $gs . 'h' . chr(80); // Height
                    $fullText .= $gs . 'w' . chr(2);  // Width
                    $fullText .= $gs . 'k' . chr(73) . chr(strlen($barcodeData)) . $barcodeData; // CODE128
                    $fullText .= "\n";
                    break;
                case 'qr':
                    $qrData = self::replacePlaceholders($element['data']['value'], $transaction);
                    $len = strlen($qrData) + 3;
                    $pL = chr($len % 256);
                    $pH = chr($len / 256);
                    $fullText .= $gs . '(k' . chr(4) . chr(0) . '1A' . chr(50) . chr(0); // Model
                    $fullText .= $gs . '(k' . chr(3) . chr(0) . '1C' . chr(5);  // Size
                    $fullText .= $gs . '(k' . chr(3) . chr(0) . '1E' . chr(48); // Error correction
                    $fullText .= $gs . '(k' . $pL . $pH . '1P0' . $qrData;       // Store data
                    $fullText .= $gs . '(k' . chr(3) . chr(0) . '1Q0';       // Print
                    $fullText .= "\n";
                    break;
                case 'sales_table':
                    // Aquí se genera la tabla de productos iterando sobre los items de la transacción
                    foreach ($transaction->items as $item) {
                        $line = "{$item->quantity} {$item->description} $" . number_format($item->line_total, 2);
                        $fullText .= $line . "\n";
                    }
                    break;
            }
            $fullText .= $alignLeft; // Reset alignment
        }
        $fullText .= $cutPaper;
        return $fullText;
    }
    
    /**
     * Reemplaza los placeholders en una cadena de texto con datos reales de la transacción.
     */
    private static function replacePlaceholders(string $text, Transaction $transaction): string
    {
        return str_replace(
            ['{{folio}}', '{{cliente.nombre}}', /* ... otros placeholders ... */],
            [$transaction->folio, $transaction->customer->name ?? 'Público General', /* ... otros datos ... */],
            $text
        );
    }
}