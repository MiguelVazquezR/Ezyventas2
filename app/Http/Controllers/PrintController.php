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
            'data_source_type' => 'required|in:transaction,product,service_order,customer', 
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
        elseif ($validated['data_source_type'] === 'transaction') {
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
}