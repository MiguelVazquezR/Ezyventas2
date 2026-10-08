<?php

namespace App\Http\Controllers;

use App\Actions\Product\AdjustProductStockAction;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductStockController extends Controller
{
    /**
     * Store a newly created resource in storage (Single Product Mode).
     */
    public function store(Request $request, Product $product, AdjustProductStockAction $action)
    {
        $validated = $request->validate([
            'type' => 'required|in:simple,variant',
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
            'quantity' => 'nullable|required_if:type,simple|numeric|min:1',
            'variants' => 'nullable|required_if:type,variant|array',
            'variants.*.id' => 'required_with:variants|exists:product_attributes,id',
            'variants.*.quantity' => 'nullable|numeric|min:0',
            
            // --- NUEVOS CAMPOS PARA GASTOS ---
            'register_expense' => 'boolean',
            'expense_amount_type' => 'nullable|in:calculated,manual',
            'expense_amount' => 'required_if:expense_amount_type,manual|nullable|numeric|min:0',
            'expense_date' => 'nullable|required_if:register_expense,true|date',
            'payment_method' => 'required_if:register_expense,true|nullable|string',
            'take_from_cash_register' => 'boolean',
            'bank_account_id' => 'required_if:payment_method,tarjeta|required_if:payment_method,transferencia|nullable|exists:bank_accounts,id',
            'cash_register_session_id' => 'nullable|exists:cash_register_sessions,id',
        ]);

        // Inyectamos el ID del producto para que el Action lo normalice
        $validated['product_id'] = $product->id;

        $action->execute($validated, auth()->user());

        return redirect()->back()->with('success', 'Stock actualizado con éxito en la sucursal.');
    }

    /**
     * Store resources in storage (Batch Mode).
     */
    public function batchStore(Request $request, AdjustProductStockAction $action)
    {
        $validated = $request->validate([
            'operation' => 'required|in:entry,exit',
            'reason' => 'required|string',
            'products' => 'required|array',
            'products.*.id' => 'required|exists:products,id',
            'products.*.type' => 'required|in:simple,variant',
            'products.*.quantity' => 'nullable|numeric|min:0',
            'products.*.variants' => 'nullable|array',
            'products.*.variants.*.id' => 'required_with:products.*.variants|exists:product_attributes,id',
            'products.*.variants.*.quantity' => 'nullable|numeric|min:0',
            
            // --- NUEVOS CAMPOS PARA GASTOS ---
            'register_expense' => 'boolean',
            'expense_amount_type' => 'nullable|in:calculated,manual',
            'expense_amount' => 'required_if:expense_amount_type,manual|nullable|numeric|min:0',
            'expense_date' => 'nullable|required_if:register_expense,true|date',
            'payment_method' => 'required_if:register_expense,true|nullable|string',
            'take_from_cash_register' => 'boolean',
            'bank_account_id' => 'required_if:payment_method,tarjeta|required_if:payment_method,transferencia|nullable|exists:bank_accounts,id',
            'cash_register_session_id' => 'nullable|exists:cash_register_sessions,id',
        ]);

        $action->execute($validated, auth()->user());

        return redirect()->back()->with('success', 'Stock actualizado con éxito en la sucursal para los productos seleccionados.');
    }
}