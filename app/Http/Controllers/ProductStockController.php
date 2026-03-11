<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SessionCashMovement;
use App\Models\BankAccount;
use App\Enums\ExpenseStatus;
use App\Enums\SessionCashMovementType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProductStockController extends Controller
{
    /**
     * Store a newly created resource in storage (Single Product Mode).
     */
    public function store(Request $request, Product $product)
    {
        Log::info("datos recibidos para actualización de stock", ['request' => $request->all(), 'product_id' => $product->id]);
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
            'payment_method' => 'required_if:register_expense,true|nullable|string',
            'take_from_cash_register' => 'boolean',
            'bank_account_id' => 'required_if:payment_method,tarjeta|required_if:payment_method,transferencia|nullable|exists:bank_accounts,id',
            'cash_register_session_id' => 'nullable|exists:cash_register_sessions,id',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $branchId = auth()->user()->branch_id;
        
        $calculatedTotal = 0;
        $descriptionParts = [];
        $totalChanged = 0;
        $changes = [];

        DB::transaction(function () use ($validated, $product, $operation, $reason, $branchId, &$calculatedTotal, &$descriptionParts, &$totalChanged, &$changes) {
            
            // 1. PRODUCTO SIMPLE
            if ($validated['type'] === 'simple') {
                $quantity = $validated['quantity'];
                
                $pivot = $product->branches()->where('branches.id', $branchId)->first()?->pivot;
                if (!$pivot) return; 

                $newStock = ($operation === 'entry') ? $pivot->current_stock + $quantity : $pivot->current_stock - $quantity;
                $pivot->current_stock = $newStock;
                $pivot->save();

                $changes['current_stock'] = ($operation === 'entry' ? '+' : '-') . $quantity;
                $totalChanged = $quantity;

                if ($operation === 'entry') {
                    $calculatedTotal += $quantity * ($product->cost_price ?? 0);
                    $descriptionParts[] = "{$quantity}x {$product->name}";
                }
            } 
            // 2. PRODUCTO CON VARIANTES
            elseif ($validated['type'] === 'variant') {
                foreach ($validated['variants'] as $vData) {
                    $qty = $vData['quantity'];
                    if ($qty > 0) {
                        $variant = $product->productAttributes()->find($vData['id']);
                        if ($variant) {
                            $vPivot = $variant->branches()->where('branches.id', $branchId)->first()?->pivot;
                            if ($vPivot) {
                                $newStock = ($operation === 'entry') ? $vPivot->current_stock + $qty : $vPivot->current_stock - $qty;
                                $vPivot->current_stock = $newStock;
                                $vPivot->save();

                                $variantName = implode(', ', $variant->attributes ?? []);
                                $changes[$variantName] = ($operation === 'entry' ? '+' : '-') . $qty;
                                $totalChanged += $qty;

                                if ($operation === 'entry') {
                                    $calculatedTotal += $qty * ($product->cost_price ?? 0);
                                    $descriptionParts[] = "{$qty}x {$product->name} ($variantName)";
                                }
                            }
                        }
                    }
                }
            }

            // 3. GENERAR GASTO AUTOMÁTICO
            if ($operation === 'entry' && ($validated['register_expense'] ?? false) && $totalChanged > 0) {
                $this->processExpense($validated, $calculatedTotal, $descriptionParts, $branchId);
            }

            // 4. REGISTRAR EL LOG DE LA ACTIVIDAD
            if ($totalChanged > 0) {
                $actionWord = $operation === 'entry' ? 'entrada' : 'salida';
                activity()
                    ->event('updated')
                    ->performedOn($product)
                    ->causedBy(auth()->user())
                    ->withProperties([
                        'attributes' => $changes,
                        'operation' => $operation,
                        'reason' => $reason
                    ])
                    ->log("Se dio {$actionWord} masiva de {$totalChanged} unidades (variantes)");
            }
        });

        return redirect()->route('products.index')->with('success', 'Stock actualizado con éxito en la sucursal.');
    }

    /**
     * Store resources in storage (Batch Mode).
     */
    public function batchStore(Request $request)
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
            'payment_method' => 'required_if:register_expense,true|nullable|string',
            'take_from_cash_register' => 'boolean',
            'bank_account_id' => 'required_if:payment_method,tarjeta|required_if:payment_method,transferencia|nullable|exists:bank_accounts,id',
            'cash_register_session_id' => 'nullable|exists:cash_register_sessions,id',
        ]);

        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $branchId = auth()->user()->branch_id;

        $calculatedTotal = 0;
        $descriptionParts = [];
        $totalItemsChanged = 0;

        DB::transaction(function () use ($validated, $operation, $reason, $branchId, &$calculatedTotal, &$descriptionParts, &$totalItemsChanged) {
            foreach ($validated['products'] as $productData) {
                $product = Product::find($productData['id']);
                if (!$product) continue;

                $changes = [];
                $productTotalChanged = 0;

                // 1. PRODUCTO SIMPLE
                if ($productData['type'] === 'simple') {
                    $qty = $productData['quantity'] ?? 0;
                    if ($qty > 0) {
                        $pivot = $product->branches()->where('branches.id', $branchId)->first()?->pivot;
                        if ($pivot) {
                            $newStock = ($operation === 'entry') ? $pivot->current_stock + $qty : $pivot->current_stock - $qty;
                            $pivot->current_stock = $newStock;
                            $pivot->save();

                            $changes['current_stock'] = ($operation === 'entry' ? '+' : '-') . $qty;
                            $productTotalChanged += $qty;

                            if ($operation === 'entry') {
                                $calculatedTotal += $qty * ($product->cost_price ?? 0);
                                $descriptionParts[] = "{$qty}x {$product->name}";
                            }
                        }
                    }
                } 
                // 2. PRODUCTO CON VARIANTES
                elseif ($productData['type'] === 'variant' && !empty($productData['variants'])) {
                    foreach ($productData['variants'] as $vData) {
                        $qty = $vData['quantity'] ?? 0;
                        if ($qty > 0) {
                            $variant = $product->productAttributes()->find($vData['id']);
                            if ($variant) {
                                $vPivot = $variant->branches()->where('branches.id', $branchId)->first()?->pivot;
                                if ($vPivot) {
                                    $newStock = ($operation === 'entry') ? $vPivot->current_stock + $qty : $vPivot->current_stock - $qty;
                                    $vPivot->current_stock = $newStock;
                                    $vPivot->save();

                                    $variantName = implode(', ', $variant->attributes ?? []);
                                    $changes[$variantName] = ($operation === 'entry' ? '+' : '-') . $qty;
                                    $productTotalChanged += $qty;

                                    if ($operation === 'entry') {
                                        $calculatedTotal += $qty * ($product->cost_price ?? 0);
                                        $descriptionParts[] = "{$qty}x {$product->name} ($variantName)";
                                    }
                                }
                            }
                        }
                    }
                }

                // 3. LOG DE ACTIVIDAD POR PRODUCTO
                if ($productTotalChanged > 0) {
                    $totalItemsChanged += $productTotalChanged;
                    $actionWord = $operation === 'entry' ? 'entrada' : 'salida';
                    activity()
                        ->event('updated')
                        ->performedOn($product)
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'attributes' => $changes,
                            'operation' => $operation,
                            'reason' => $reason
                        ])
                        ->log("Se dio {$actionWord} masiva de {$productTotalChanged} unidades (variantes)");
                }
            }

            // 4. GENERAR GASTO AUTOMÁTICO
            if ($operation === 'entry' && ($validated['register_expense'] ?? false) && $totalItemsChanged > 0) {
                $this->processExpense($validated, $calculatedTotal, $descriptionParts, $branchId);
            }
        });

        return redirect()->route('products.index')->with('success', 'Stock actualizado con éxito en la sucursal para los productos seleccionados.');
    }


    /**
     * Procesa la creación de un gasto a partir del inventario ingresado.
     */
    private function processExpense(array $validated, float $calculatedTotal, array $descriptionParts, int $branchId)
    {
        $amount = ($validated['expense_amount_type'] === 'manual') ? (float) $validated['expense_amount'] : $calculatedTotal;
        
        // Si el monto total final es 0, no tiene caso registrar un gasto
        if ($amount <= 0) return;

        $description = "Compra de inventario: " . implode(', ', $descriptionParts);
        // Limitar la descripción para no exceder posibles límites de base de datos
        if (strlen($description) > 250) {
            $description = substr($description, 0, 247) . '...';
        }

        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'Compra de productos/insumos', 'subscription_id' => auth()->user()->branch->subscription_id],
            ['description' => 'Categoría generada automáticamente para el reabastecimiento de inventario']
        );

        $cashMovementId = null;

        if ($validated['payment_method'] === 'efectivo' && ($validated['take_from_cash_register'] ?? false)) {
            if (!empty($validated['cash_register_session_id'])) {
                $movement = SessionCashMovement::create([
                    'cash_register_session_id' => $validated['cash_register_session_id'],
                    'user_id' => auth()->id(),
                    'type' => SessionCashMovementType::OUTFLOW,
                    'amount' => $amount,
                    'description' => "Pago de compra de stock"
                ]);
                $cashMovementId = $movement->id;
            } else {
                throw ValidationException::withMessages(['take_from_cash_register' => 'No hay una sesión de caja activa para tomar el dinero.']);
            }
        }

        if (in_array($validated['payment_method'], ['tarjeta', 'transferencia']) && !empty($validated['bank_account_id'])) {
            $bankAccount = BankAccount::find($validated['bank_account_id']);
            if ($bankAccount) {
                $bankAccount->decrement('balance', $amount);
            }
        }

        Expense::create([
            'folio' => 'Compra de productos/insumos',
            'user_id' => auth()->id(),
            'branch_id' => $branchId,
            'amount' => $amount,
            'expense_category_id' => $category->id,
            'expense_date' => now(),
            'status' => ExpenseStatus::PAID,
            'description' => $description,
            'payment_method' => $validated['payment_method'],
            'bank_account_id' => $validated['bank_account_id'] ?? null,
            'session_cash_movement_id' => $cashMovementId,
        ]);
    }
}