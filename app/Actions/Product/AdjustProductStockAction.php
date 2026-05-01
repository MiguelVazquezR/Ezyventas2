<?php

namespace App\Actions\Product;

use App\Enums\ExpenseStatus;
use App\Enums\SessionCashMovementType;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\SessionCashMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustProductStockAction
{
    /**
     * Ejecuta el ajuste de inventario. Funciona tanto para 1 producto como para múltiples (Batch).
     */
    public function execute(array $validated, User $user): void
    {
        $operation = $validated['operation'];
        $reason = $validated['reason'];
        $branchId = $user->branch_id;

        $calculatedTotal = 0;
        $descriptionParts = [];
        $totalItemsChanged = 0;

        DB::transaction(function () use ($validated, $operation, $reason, $branchId, $user, &$calculatedTotal, &$descriptionParts, &$totalItemsChanged) {
            
            // Normalizar datos: Si no viene como "products" (Batch), lo empaquetamos (Single)
            $productsData = $validated['products'] ?? [];
            if (empty($productsData) && isset($validated['type'])) {
                $productsData[] = [
                    'id' => $validated['product_id'],
                    'type' => $validated['type'],
                    'quantity' => $validated['quantity'] ?? 0,
                    'variants' => $validated['variants'] ?? [],
                ];
            }

            foreach ($productsData as $productData) {
                $product = Product::find($productData['id']);
                if (!$product) continue;

                // 1. PRODUCTO SIMPLE O A GRANEL
                if (in_array($productData['type'], ['simple', 'bulk'])) {
                    $qty = (float) ($productData['quantity'] ?? 0);
                    if ($qty > 0) {
                        $this->applyStockChangeToModel($product, $qty, $operation, $branchId, $user, $reason);
                        $totalItemsChanged += $qty;

                        if ($operation === 'entry') {
                            $calculatedTotal += $qty * ($product->cost_price ?? 0);
                            $descriptionParts[] = "{$qty}x {$product->name}";
                        }
                    }
                } 
                // 2. PRODUCTO CON VARIANTES
                elseif ($productData['type'] === 'variant' && !empty($productData['variants'])) {
                    foreach ($productData['variants'] as $vData) {
                        $qty = (float) ($vData['quantity'] ?? 0);
                        if ($qty > 0) {
                            $variant = ProductAttribute::find($vData['id']);
                            if ($variant) {
                                $this->applyStockChangeToModel($variant, $qty, $operation, $branchId, $user, $reason);
                                $totalItemsChanged += $qty;

                                if ($operation === 'entry') {
                                    $calculatedTotal += $qty * ($product->cost_price ?? 0);
                                    $variantName = implode(', ', $variant->attributes ?? []);
                                    $descriptionParts[] = "{$qty}x {$product->name} ($variantName)";
                                }
                            }
                        }
                    }
                }
            }

            // 3. GENERAR GASTO AUTOMÁTICO (Solo en entradas)
            if ($operation === 'entry' && ($validated['register_expense'] ?? false) && $totalItemsChanged > 0) {
                $this->processExpense($validated, $calculatedTotal, $descriptionParts, $branchId, $user);
            }
        });
    }

    /**
     * Delega el cambio físico a los modelos Product o ProductAttribute
     */
    private function applyStockChangeToModel($model, float $qty, string $operation, int $branchId, User $user, string $reason): void
    {
        // REFACTOR: Usamos los métodos que ya existen en tus modelos
        if ($operation === 'entry') {
            $model->restock($branchId, $qty, $user, "Entrada manual de inventario: {$reason}");
        } else {
            $model->deductStock($branchId, $qty, $user, "Salida manual de inventario: {$reason}");
        }
    }

    /**
     * Procesa la creación de un gasto a partir del inventario ingresado.
     */
    private function processExpense(array $validated, float $calculatedTotal, array $descriptionParts, int $branchId, User $user): void
    {
        $amount = ($validated['expense_amount_type'] === 'manual') ? (float) $validated['expense_amount'] : $calculatedTotal;
        
        if ($amount <= 0) return;

        $description = "Compra de inventario: " . implode(', ', $descriptionParts);
        if (strlen($description) > 250) {
            $description = substr($description, 0, 247) . '...';
        }

        $category = ExpenseCategory::firstOrCreate(
            ['name' => 'Compra de productos/insumos', 'subscription_id' => $user->branch->subscription_id],
            ['description' => 'Categoría generada automáticamente para el reabastecimiento de inventario']
        );

        $cashMovementId = null;

        if ($validated['payment_method'] === 'efectivo' && ($validated['take_from_cash_register'] ?? false)) {
            if (!empty($validated['cash_register_session_id'])) {
                $movement = SessionCashMovement::create([
                    'cash_register_session_id' => $validated['cash_register_session_id'],
                    'user_id' => $user->id,
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
                // REFACTOR: Uso del método del modelo
                $bankAccount->withdraw($amount);
            }
        }

        Expense::create([
            'folio' => 'Compra de productos/insumos',
            'user_id' => $user->id,
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