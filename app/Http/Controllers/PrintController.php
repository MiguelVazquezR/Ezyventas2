<?php

namespace App\Http\Controllers;

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
            'data_source_type' => 'required|in:transaction,product,service_order',
            'data_source_id' => 'required|integer',
            // --- Añadir validación para los desfases opcionales ---
            'offset_x' => 'nullable|numeric',
            'offset_y' => 'nullable|numeric',
            // --- Añadir validación para los desfases opcionales ---
        ]);

        $template = PrintTemplate::find($validated['template_id']);
        $user = Auth::user();
        if ($template->subscription_id !== $user->branch->subscription_id) {
            abort(403);
        }

        $dataSource = null;
        if ($validated['data_source_type'] === 'transaction') {
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

        // --- INICIO: Recoger los desfases y prepararlos en un array de opciones ---
        $options = [
            'offset_x' => $validated['offset_x'] ?? 0,
            'offset_y' => $validated['offset_y'] ?? 0,
        ];
        // --- FIN: Recoger los desfases y prepararlos en un array de opciones ---

        // Pasar la plantilla, la fuente de datos y las opciones al servicio de codificación
        $operations = PrintEncoderService::encode($template, $dataSource, $options);

        return response()->json([
            'operations' => $operations,
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
            'feedLines' => $template->content['config']['feedLines'] ?? 0,
        ]);
    }
}