<?php

namespace App\Http\Controllers;

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
