<?php

namespace App\Http\Controllers;

use App\Models\PrintTemplate;
use App\Models\Transaction;
use App\Services\PrintEncoderService;
use App\Services\Printing\PrintDataSourceResolver;
use App\Services\WhatsAppTicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrintController extends Controller
{
    public function __construct(
        protected WhatsAppTicketService $whatsAppTicketService,
        protected PrintDataSourceResolver $printDataSources,
    ) {}

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

        $dataSource = $this->printDataSources->resolve(
            $validated['data_source_type'],
            (int) $validated['data_source_id'],
            $user
        );

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

        $dataSource = $this->printDataSources->resolve(
            $validated['data_source_type'],
            (int) $validated['data_source_id'],
            $user
        );

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

        $dataSource = $this->printDataSources->resolve(
            $validated['data_source_type'],
            (int) $validated['data_source_id'],
            $user
        );

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

        $dataSource = $this->printDataSources->resolve(
            $validated['data_source_type'],
            (int) $validated['data_source_id'],
            Auth::user()
        );

        if (!$dataSource instanceof Transaction) {
            return response()->json([
                'ticket' => null,
                'customer_phone' => null,
                'customer_id' => null,
            ]);
        }

        $dataSource->loadMissing(['customer', 'branch.subscription']);
        $customer = $dataSource->customer;

        // Pedidos (creados en POS como "por entregar"): ticket de pedido con su estado actual.
        if ($dataSource->isOrder()) {
            $contactInfo = $dataSource->contact_info;
            $contactPhone = is_array($contactInfo) ? ($contactInfo['phone'] ?? null) : null;

            return response()->json([
                'ticket' => $this->whatsAppTicketService->buildOrderPayload($dataSource),
                'customer_phone' => $contactPhone ?: ($customer?->phone ?: null),
                'customer_id' => $customer?->id ?: null,
            ]);
        }

        return response()->json([
            'ticket' => $this->whatsAppTicketService->buildSalePayload($dataSource),
            'customer_phone' => $customer?->phone ?: null,
            'customer_id' => $customer?->id ?: null,
        ]);
    }
}
