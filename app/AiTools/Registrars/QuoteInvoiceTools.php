<?php

namespace App\AiTools\Registrars;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Services\QuoteInvoiceReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class QuoteInvoiceTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_status_summary')
                    ->for('Obtener resumen de cotizaciones agrupadas por estado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getQuoteStatusSummary(
                            $branchId, Carbon::parse($start_date), Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'quotes.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('quote_conversion_rate')
                    ->for('Obtener la tasa de conversión de cotizaciones a ventas en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getConversionRate(
                            $branchId, Carbon::parse($start_date), Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'invoices.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('invoice_status_summary')
                    ->for('Obtener resumen de facturas (CFDI) agrupadas por estado')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getInvoiceStatusSummary(
                            $branchId, Carbon::parse($start_date), Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'invoices.access',
                'category'   => 'quotes and invoices',
                'tool'       => (new Tool)->as('invoice_aging')
                    ->for('Listar facturas (CFDI) pendientes de cobro, agrupadas por antigüedad (0-30, 31-60, 61-90, 90+ días)')
                    ->withStringParameter('as_of_date', 'Fecha de referencia en formato YYYY-MM-DD (por defecto hoy)')
                    ->using(function (?string $as_of_date = null) use ($branchId) {
                        $result = app(QuoteInvoiceReportService::class)->getInvoiceAging($branchId, $as_of_date);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'quotes.access',
                'category'   => 'quotes',
                'tool'       => (new Tool)->as('search_quotes')
                    ->for('Buscar cotizaciones por folio, cliente, estado o rango de fechas')
                    ->withStringParameter('query', 'Texto a buscar en folio o nombre del destinatario')
                    ->withStringParameter('status', 'Estado de la cotización (borrador, enviada, aceptada, rechazada, venta_generada) o null')
                    ->withStringParameter('date_from', 'Fecha inicial YYYY-MM-DD o null')
                    ->withStringParameter('date_to', 'Fecha final YYYY-MM-DD o null')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 15, máximo 30)')
                    ->using(function (string $query, ?string $status = null, ?string $date_from = null, ?string $date_to = null, ?int $limit = 15) use ($branchId) {
                        $q = Quote::query()->where('branch_id', $branchId);
                        $q->where(function ($sub) use ($query) {
                            $sub->where('folio', 'LIKE', "%{$query}%")
                               ->orWhere('recipient_name', 'LIKE', "%{$query}%");
                        });
                        if ($status && $status !== 'null') { $q->where('status', $status); }
                        if ($date_from && $date_from !== 'null') { $q->whereDate('created_at', '>=', $date_from); }
                        if ($date_to && $date_to !== 'null') { $q->whereDate('created_at', '<=', $date_to); }
                        $quotes = $q->with('customer:id,name')->orderBy('created_at', 'desc')
                            ->limit(min($limit ?? 15, 30))
                            ->get(['id', 'folio', 'recipient_name', 'status', 'total_amount', 'created_at', 'expiry_date', 'customer_id']);
                        return json_encode($quotes, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'quotes.access',
                'category'   => 'quotes',
                'tool'       => (new Tool)->as('get_quote_details')
                    ->for('Obtener el detalle completo de una cotización: folio, cliente, items, totales, versiones')
                    ->withNumberParameter('quote_id', 'ID de la cotización')
                    ->using(function (int $quote_id) use ($branchId) {
                        $quote = Quote::where('branch_id', $branchId)
                            ->with(['items', 'customer:id,name,email,phone', 'user:id,name', 'versions:id,parent_quote_id,folio,status,total_amount,created_at', 'transaction:id,transactionable_type,transactionable_id,total_amount'])
                            ->findOrFail($quote_id);

                        return json_encode([
                            'id' => $quote->id, 'folio' => $quote->folio, 'status' => $quote->status->value,
                            'recipient_name' => $quote->recipient_name, 'recipient_email' => $quote->recipient_email,
                            'recipient_phone' => $quote->recipient_phone,
                            'customer' => $quote->customer ? ['id' => $quote->customer->id, 'name' => $quote->customer->name, 'email' => $quote->customer->email, 'phone' => $quote->customer->phone] : null,
                            'created_by' => $quote->user?->name,
                            'subtotal' => (float) $quote->subtotal, 'total_discount' => (float) $quote->total_discount,
                            'total_tax' => (float) $quote->total_tax, 'tax_type' => $quote->tax_type,
                            'tax_rate' => $quote->tax_rate, 'shipping_cost' => (float) $quote->shipping_cost,
                            'total_amount' => (float) $quote->total_amount, 'notes' => $quote->notes,
                            'shipping_address' => $quote->shipping_address,
                            'created_at' => $quote->created_at?->toDateTimeString(),
                            'expiry_date' => $quote->expiry_date?->toDateString(),
                            'version_number' => $quote->version_number,
                            'items' => $quote->items->map(fn ($i) => [
                                'id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity,
                                'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
                                'variant_details' => $i->variant_details, 'itemable_type' => $i->itemable_type,
                                'itemable_id' => $i->itemable_id,
                            ]),
                            'versions' => $quote->versions->map(fn ($v) => [
                                'id' => $v->id, 'folio' => $v->folio, 'status' => $v->status->value,
                                'total_amount' => (float) $v->total_amount, 'created_at' => $v->created_at?->toDateTimeString(),
                            ]),
                            'has_transaction' => $quote->transaction !== null,
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'quotes.create',
                'category'   => 'quotes (crear)',
                'tool'       => (new Tool)->as('create_quote')
                    ->for('Crear una nueva cotización. REQUIERE modo escritura activado.')
                    ->withStringParameter('recipient_name', 'Nombre del cliente o destinatario')
                    ->withStringParameter('recipient_email', 'Email del destinatario (opcional)')
                    ->withStringParameter('recipient_phone', 'Teléfono del destinatario (opcional)')
                    ->withStringParameter('notes', 'Notas o condiciones de la cotización (opcional)')
                    ->withStringParameter('expiry_date', 'Fecha de expiración YYYY-MM-DD (opcional)')
                    ->withNumberParameter('customer_id', 'ID del cliente registrado (opcional)')
                    ->withStringParameter('tax_type', 'Tipo de impuesto: IVA, IEPS, exento (por defecto IVA)')
                    ->withNumberParameter('tax_rate', 'Tasa de impuesto en porcentaje (por defecto 16)')
                    ->using(function (string $recipient_name, ?string $recipient_email = null, ?string $recipient_phone = null, ?string $notes = null, ?string $expiry_date = null, ?int $customer_id = null, ?string $tax_type = 'IVA', ?float $tax_rate = 16) use ($branchId, $user) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
                        if (! $gate->isEnabled()) { return json_encode(['error' => $gate->rejectionMessage()]); }

                        $folio = Quote::generateFolio($branchId);

                        $quote = Quote::create([
                            'folio' => $folio, 'branch_id' => $branchId, 'user_id' => $user->id,
                            'customer_id' => $customer_id, 'recipient_name' => $recipient_name,
                            'recipient_email' => $recipient_email, 'recipient_phone' => $recipient_phone,
                            'notes' => $notes,
                            'expiry_date' => $expiry_date && $expiry_date !== 'null' ? Carbon::parse($expiry_date) : null,
                            'status' => QuoteStatus::DRAFT, 'subtotal' => 0, 'total_discount' => 0,
                            'total_tax' => 0, 'tax_type' => $tax_type ?? 'IVA', 'tax_rate' => $tax_rate ?? 16,
                            'shipping_cost' => 0, 'total_amount' => 0, 'version_number' => 1,
                        ]);

                        return json_encode([
                            'message' => 'Cotización creada exitosamente.',
                            'quote' => [
                                'id' => $quote->id, 'folio' => $quote->folio, 'recipient_name' => $quote->recipient_name,
                                'status' => $quote->status->value, 'total_amount' => (float) $quote->total_amount,
                                'created_at' => $quote->created_at?->toDateTimeString(),
                                'expiry_date' => $quote->expiry_date?->toDateString(),
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}