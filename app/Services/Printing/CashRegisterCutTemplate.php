<?php

namespace App\Services\Printing;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
use App\Models\PrintTemplate;

/**
 * Cut template used when the business has not designed one.
 *
 * The phone must be able to reprint a shift with a single call, even before the
 * business creates its own template (context `cash_register`), so a printable
 * cut is defined here: an unsaved PrintTemplate with the figures of the shift
 * (`{{corte.*}}`).
 */
class CashRegisterCutTemplate
{
    public static function make(): PrintTemplate
    {
        return new PrintTemplate([
            'name' => 'Corte de caja',
            'type' => TemplateType::SALE_TICKET,
            'context_type' => TemplateContextType::CASH_REGISTER->value,
            'content' => [
                'config' => ['paperWidth' => '80mm', 'feedLines' => 3],
                'elements' => self::elements(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function elements(): array
    {
        $line = [
            ['text' => 'CORTE DE CAJA', 'y' => 0],
            ['text' => 'Caja: {{corte.caja}}', 'y' => 8],
            ['text' => 'Folio: {{corte.folio}}', 'y' => 16],
            ['text' => 'Cajero: {{corte.cajero}}', 'y' => 24],
            ['text' => 'Apertura: {{corte.fecha_apertura}}', 'y' => 32],
            ['text' => 'Cierre: {{corte.fecha_cierre}}', 'y' => 40],
            ['text' => '----------------', 'y' => 48],
            ['text' => 'Fondo inicial: {{corte.fondo_inicial}}', 'y' => 56],
            ['text' => 'Ventas efectivo: {{corte.ventas_efectivo}}', 'y' => 64],
            ['text' => 'Ingresos: {{corte.ingresos}}', 'y' => 72],
            ['text' => 'Retiros: {{corte.retiros}}', 'y' => 80],
            ['text' => 'Esperado en caja: {{corte.esperado}}', 'y' => 88],
            ['text' => 'Contado: {{corte.contado}}', 'y' => 96],
            ['text' => 'Diferencia: {{corte.diferencia}}', 'y' => 104],
            ['text' => '----------------', 'y' => 112],
            ['text' => 'Tarjeta: {{corte.tarjeta}}', 'y' => 120],
            ['text' => 'Transferencia: {{corte.transferencia}}', 'y' => 128],
            ['text' => 'Saldo a favor: {{corte.saldo}}', 'y' => 136],
            ['text' => 'Total ventas: {{corte.total_ventas}}', 'y' => 144],
            ['text' => 'Ventas: {{corte.ventas}}  Pagos: {{corte.pagos}}', 'y' => 152],
            ['text' => 'Notas: {{corte.notas}}', 'y' => 160],
        ];

        return array_map(fn (array $element) => [
            'type' => 'text',
            'data' => [
                // Tickets (ESC/POS) read the text from `data.text`.
                'text' => $element['text'],
                'x' => 0,
                'y' => $element['y'],
                'font_size' => 3,
            ],
        ], $line);
    }
}
