<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Customer; // <-- Importante
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Services\PrintEncoderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrintController extends Controller
{
    public function generatePayload(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:print_templates,id',
            // Agregamos 'customer' a la validación
            'data_source_type' => 'required', 
            'data_source_id' => 'required|integer',
            'offset_x' => 'nullable|numeric',
            'offset_y' => 'nullable|numeric',
            'open_drawer' => 'nullable|boolean',
        ]);

        $template = PrintTemplate::find($validated['template_id']);
        $user = Auth::user();
        if ($template->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        $dataSource = null;

        // --- Lógica para Cliente ---
        if ($validated['data_source_type'] === 'customer') {
            $dataSource = Customer::where('id', $validated['data_source_id'])
                ->where(function($q) use ($user) {
                    // Verificación de seguridad: El cliente debe pertenecer a una sucursal de la misma suscripción
                    $q->whereHas('branch', function($b) use ($user) {
                        $b->where('subscription_id', $user->branch->subscription_id);
                    })->orWhereNull('branch_id'); // O ser global si manejas clientes globales (opcional)
                })->first();

            if (!$dataSource) abort(404);
        }
        // ---------------------------
        elseif ($validated['data_source_type'] === 'transaction' || $validated['data_source_type'] === 'pos' || $validated['data_source_type'] === 'general') {
            $dataSource = Transaction::with(['customer', 'items.itemable'])->find($validated['data_source_id']);
            if (!$dataSource || $dataSource->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
        } elseif ($validated['data_source_type'] === 'product') {
            $dataSource = Product::find($validated['data_source_id']);
            if (!$dataSource || $dataSource->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
        } elseif ($validated['data_source_type'] === 'service_order') {
            $dataSource = ServiceOrder::find($validated['data_source_id']);
            if (!$dataSource || $dataSource->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
        }
        
        if (!$dataSource) {
            abort(404, 'Data source not found.');
        }

        $options = [
            'offset_x' => $validated['offset_x'] ?? 0,
            'offset_y' => $validated['offset_y'] ?? 0,
            'open_drawer' => $validated['open_drawer'] ?? false,
        ];

        $operations = PrintEncoderService::encode($template, $dataSource, $options);

        return response()->json([
            'operations' => $operations,
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
            'feedLines' => $template->content['config']['feedLines'] ?? 0,
        ]);
    }

    /**
     * Genera payload para impresión Bluetooth: comandos ESC/POS crudos en Base64.
     * Endpoint usado exclusivamente por Web Bluetooth API.
     */
    public function bluetoothPayload(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:print_templates,id',
            'data_source_type' => 'required',
            'data_source_id' => 'required|integer',
            'open_drawer' => 'nullable|boolean',
        ]);

        $template = PrintTemplate::find($validated['template_id']);
        $user = Auth::user();
        if ($template->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        $dataSource = $this->resolveDataSource($validated['data_source_type'], $validated['data_source_id'], $user);

        $options = [
            'open_drawer' => $validated['open_drawer'] ?? false,
        ];

        $base64Commands = PrintEncoderService::encodeEscPosToBase64($template, $dataSource, $options);

        return response()->json([
            'commands_base64' => $base64Commands,
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
        ]);
    }

    /**
     * Genera HTML del ticket para vista previa / AirPrint / PDF.
     * Usado como fallback en navegadores sin soporte Web Bluetooth (iOS/Safari).
     */
    public function ticketHtml(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:print_templates,id',
            'data_source_type' => 'required',
            'data_source_id' => 'required|integer',
        ]);

        $template = PrintTemplate::find($validated['template_id']);
        $user = Auth::user();
        if ($template->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        $dataSource = $this->resolveDataSource($validated['data_source_type'], $validated['data_source_id'], $user);

        $html = PrintEncoderService::encodeTicketToHtml($template, $dataSource);

        return response()->json([
            'html' => $html,
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
            'template_name' => $template->name,
        ]);
    }

    /**
     * Devuelve los datos del ticket de una venta formateados para WhatsApp,
     * junto con el teléfono del cliente relacionado (si existe).
     */
    public function whatsappTicket(Request $request)
    {
        $validated = $request->validate([
            'data_source_type' => 'required',
            'data_source_id' => 'required|integer',
        ]);

        $user = Auth::user();
        $dataSource = $this->resolveDataSource($validated['data_source_type'], $validated['data_source_id'], $user);

        if (!$dataSource instanceof Transaction) {
            return response()->json([
                'ticket' => null,
                'customer_phone' => null,
                'customer_id' => null,
            ]);
        }

        $dataSource->loadMissing(['customer', 'items', 'payments', 'branch.subscription']);

        $subscription = $dataSource->branch?->subscription;
        $customer = $dataSource->customer;

        $items = $dataSource->items->map(fn ($item) => [
            'cantidad' => (float) $item->quantity,
            'descripcion' => $item->description,
            'total' => '$' . number_format((float) $item->line_total, 2),
        ])->values();

        $total = (float) $dataSource->subtotal - (float) $dataSource->total_discount + (float) $dataSource->total_tax;
        $totalPaid = (float) $dataSource->payments->sum('amount');
        $change = max(0, $totalPaid - $total);

        $ticket = [
            'businessName' => $subscription?->commercial_name ?: ($dataSource->branch?->name ?: 'Mi Negocio'),
            'title' => 'TICKET DE VENTA',
            'date' => Carbon::parse($dataSource->created_at)->format('d/m/Y - H:i'),
            'folio' => $dataSource->folio,
            'customer' => $customer?->name ?: 'Público en General',
            'items' => $items,
            'totalPaid' => '$' . number_format($total, 2) . ' MXN',
            'paymentMethod' => $this->formatPaymentMethod($dataSource->payments, $totalPaid, $change),
            'address' => $customer ? implode(', ', array_filter((array) ($customer->address ?? []))) : '',
            'finalMessage' => '¡Gracias por tu compra!',
        ];

        return response()->json([
            'ticket' => $ticket,
            'customer_phone' => $customer?->phone ?: null,
            'customer_id' => $customer?->id ?: null,
        ]);
    }

    /**
     * Formatea el método de pago para el ticket de WhatsApp.
     * Para efectivo puro incluye el monto pagado y el cambio calculado.
     */
    private function formatPaymentMethod($payments, float $totalPaid, float $change): string
    {
        $methods = $payments->pluck('payment_method.value')->unique()->values();

        if ($methods->isEmpty()) {
            return '';
        }

        if ($methods->count() === 1 && $methods->first() === 'efectivo') {
            return 'Efectivo (Pagado: $' . number_format($totalPaid, 2) . ' | Cambio: $' . number_format($change, 2) . ')';
        }

        return $methods->map(fn ($method) => ucfirst($method))->implode(', ');
    }

    private function resolveDataSource(string $type, int $id, $user)
    {
        if ($type === 'customer') {
            return Customer::where('id', $id)
                ->where(function ($q) use ($user) {
                    $q->whereHas('branch', function ($b) use ($user) {
                        $b->where('subscription_id', $user->branch->subscription_id);
                    })->orWhereNull('branch_id');
                })->firstOrFail();
        }

        if (in_array($type, ['transaction', 'pos', 'general'])) {
            $source = Transaction::with(['customer', 'items.itemable'])->find($id);
            if (!$source || $source->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
            return $source;
        }

        if ($type === 'product') {
            $source = Product::find($id);
            if (!$source || $source->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
            return $source;
        }

        if ($type === 'service_order') {
            $source = ServiceOrder::find($id);
            if (!$source || $source->branch->subscription_id !== $user->branch->subscription_id) {
                abort(404);
            }
            return $source;
        }

        abort(404, 'Data source not found.');
    }
}
