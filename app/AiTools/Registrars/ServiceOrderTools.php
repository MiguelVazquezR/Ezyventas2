<?php

namespace App\AiTools\Registrars;

use App\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Services\ServiceOrderReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ServiceOrderTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        $branchId = $user->branch_id;

        return [
            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_status_summary')
                    ->for('Obtener el resumen de órdenes de servicio agrupadas por estado')
                    ->using(function () use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getStatusSummary($branchId);
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_workload')
                    ->for('Obtener la carga de trabajo por técnico en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getWorkloadByTechnician(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('service_order_turnaround')
                    ->for('Obtener el tiempo promedio de atención de órdenes de servicio en un período')
                    ->withStringParameter('start_date', 'Fecha inicial en formato YYYY-MM-DD')
                    ->withStringParameter('end_date', 'Fecha final en formato YYYY-MM-DD')
                    ->using(function (string $start_date, string $end_date) use ($branchId) {
                        $result = app(ServiceOrderReportService::class)->getAverageTurnaroundTime(
                            $branchId,
                            Carbon::parse($start_date),
                            Carbon::parse($end_date),
                        );
                        return json_encode($result, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('search_service_orders')
                    ->for('Buscar órdenes de servicio por folio, nombre de cliente, técnico, estado o rango de fechas')
                    ->withStringParameter('query', 'Texto a buscar en folio, nombre de cliente o técnico')
                    ->withStringParameter('status', 'Estado de la orden (pendiente, en_progreso, completado, entregado, cancelado) o null')
                    ->withStringParameter('date_from', 'Fecha inicial YYYY-MM-DD o null')
                    ->withStringParameter('date_to', 'Fecha final YYYY-MM-DD o null')
                    ->withNumberParameter('limit', 'Cantidad máxima de resultados (por defecto 15, máximo 30)')
                    ->using(function (string $query, ?string $status = null, ?string $date_from = null, ?string $date_to = null, ?int $limit = 15) use ($branchId) {
                        $q = ServiceOrder::query()->where('branch_id', $branchId);

                        $q->where(function ($sub) use ($query) {
                            $sub->where('folio', 'LIKE', "%{$query}%")
                               ->orWhere('customer_name', 'LIKE', "%{$query}%")
                               ->orWhere('technician_name', 'LIKE', "%{$query}%");
                        });

                        if ($status && $status !== 'null') { $q->where('status', $status); }
                        if ($date_from && $date_from !== 'null') { $q->whereDate('received_at', '>=', $date_from); }
                        if ($date_to && $date_to !== 'null') { $q->whereDate('received_at', '<=', $date_to); }

                        $orders = $q->orderBy('received_at', 'desc')
                            ->limit(min($limit ?? 15, 30))
                            ->get(['id', 'folio', 'customer_name', 'technician_name', 'status', 'received_at', 'promised_at', 'final_total']);

                        return json_encode($orders, JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.access',
                'category'   => 'service orders',
                'tool'       => (new Tool)->as('get_service_order_details')
                    ->for('Obtener el detalle completo de una orden de servicio: folio, cliente, técnico, diagnóstico, items, totales')
                    ->withNumberParameter('service_order_id', 'ID de la orden de servicio')
                    ->using(function (int $service_order_id) use ($branchId) {
                        $order = ServiceOrder::where('branch_id', $branchId)
                            ->with(['items', 'customer:id,name,email,phone', 'quote:id,folio', 'transaction:id,transactionable_type,transactionable_id,total_amount'])
                            ->findOrFail($service_order_id);

                        return json_encode([
                            'id'                    => $order->id,
                            'folio'                 => $order->folio,
                            'status'                => $order->status->value,
                            'customer'              => $order->customer ? ['id' => $order->customer->id, 'name' => $order->customer->name, 'email' => $order->customer->email, 'phone' => $order->customer->phone] : null,
                            'customer_name'         => $order->customer_name,
                            'customer_email'        => $order->customer_email,
                            'customer_phone'        => $order->customer_phone,
                            'technician_name'       => $order->technician_name,
                            'technician_commission_type'  => $order->technician_commission_type,
                            'technician_commission_value' => $order->technician_commission_value,
                            'item_description'      => $order->item_description,
                            'reported_problems'     => $order->reported_problems,
                            'technician_diagnosis'  => $order->technician_diagnosis,
                            'received_at'           => $order->received_at?->toDateTimeString(),
                            'promised_at'           => $order->promised_at?->toDateTimeString(),
                            'subtotal'              => (float) $order->subtotal,
                            'discount_type'         => $order->discount_type,
                            'discount_amount'       => (float) $order->discount_amount,
                            'final_total'           => (float) $order->final_total,
                            'items'                 => $order->items->map(fn ($i) => [
                                'id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity,
                                'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
                                'itemable_type' => $i->itemable_type, 'itemable_id' => $i->itemable_id,
                            ]),
                            'quote'                 => $order->quote ? ['id' => $order->quote->id, 'folio' => $order->quote->folio] : null,
                            'has_transaction'       => $order->transaction !== null,
                        ], JSON_PRETTY_PRINT);
                    }),
            ],

            [
                'permission' => 'services.orders.create',
                'category'   => 'service orders (crear)',
                'tool'       => (new Tool)->as('create_service_order')
                    ->for('Crear una nueva orden de servicio. REQUIERE modo escritura activado.')
                    ->withStringParameter('customer_name', 'Nombre del cliente')
                    ->withStringParameter('customer_email', 'Email del cliente (opcional)')
                    ->withStringParameter('customer_phone', 'Teléfono del cliente (opcional)')
                    ->withStringParameter('item_description', 'Descripción del equipo o artículo recibido')
                    ->withStringParameter('reported_problems', 'Problemas reportados por el cliente')
                    ->withStringParameter('technician_name', 'Nombre del técnico asignado (opcional)')
                    ->withStringParameter('received_at', 'Fecha de recepción YYYY-MM-DD (por defecto hoy)')
                    ->withStringParameter('promised_at', 'Fecha prometida de entrega YYYY-MM-DD (opcional)')
                    ->withStringParameter('notes', 'Notas adicionales (opcional)')
                    ->using(function (string $customer_name, string $item_description, string $reported_problems, ?string $customer_email = null, ?string $customer_phone = null, ?string $technician_name = null, ?string $received_at = null, ?string $promised_at = null, ?string $notes = null) use ($branchId, $user) {
                        $gate = app(\App\AiTools\WriteModeGate::class);
                        if (! $gate->isEnabled()) { return json_encode(['error' => $gate->rejectionMessage()]); }

                        $folio = ServiceOrder::generateFolio($branchId);
                        $receivedDate = $received_at && $received_at !== 'null' ? Carbon::parse($received_at) : now();

                        $order = ServiceOrder::create([
                            'folio' => $folio, 'branch_id' => $branchId, 'user_id' => $user->id,
                            'customer_name' => $customer_name, 'customer_email' => $customer_email,
                            'customer_phone' => $customer_phone, 'item_description' => $item_description,
                            'reported_problems' => $reported_problems, 'technician_name' => $technician_name,
                            'received_at' => $receivedDate,
                            'promised_at' => $promised_at && $promised_at !== 'null' ? Carbon::parse($promised_at) : null,
                            'status' => ServiceOrderStatus::Pending, 'subtotal' => 0, 'final_total' => 0,
                        ]);

                        return json_encode([
                            'message' => 'Orden de servicio creada exitosamente.',
                            'service_order' => [
                                'id' => $order->id, 'folio' => $order->folio, 'customer_name' => $order->customer_name,
                                'status' => $order->status->value, 'received_at' => $order->received_at?->toDateTimeString(),
                                'promised_at' => $order->promised_at?->toDateTimeString(),
                            ],
                        ], JSON_PRETTY_PRINT);
                    }),
            ],
        ];
    }
}