<?php

namespace App\Http\Controllers;

use App\Models\PrintTemplate;
use App\Models\Transaction;
use App\Services\EscPosEncoderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrintController extends Controller
{
    public function generatePayload(Request $request)
    {
        $validated = $request->validate([
            'template_id' => 'required|exists:print_templates,id',
            'data_source_type' => 'required|in:transaction', // Se puede expandir a 'service_order', etc.
            'data_source_id' => 'required|integer',
        ]);

        $template = PrintTemplate::find($validated['template_id']);
        $user = Auth::user();
        if ($template->subscription_id !== $user->branch->subscription_id) abort(403);

        $dataSource = null;
        if ($validated['data_source_type'] === 'transaction') {
            $dataSource = Transaction::with(['customer', 'items.itemable'])->find($validated['data_source_id']);
            if (!$dataSource || $dataSource->branch->subscription_id !== $user->branch->subscription_id) abort(404);
        }
        if (!$dataSource) abort(404, 'Data source not found.');

        $operations = EscPosEncoderService::encode($template, $dataSource);

        return response()->json([
            'operations' => $operations,
            'paperWidth' => $template->content['config']['paperWidth'] ?? '80mm',
            'feedLines' => $template->content['config']['feedLines'] ?? 0,
        ]);
    }
}