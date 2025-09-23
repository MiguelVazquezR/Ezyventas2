<?php

namespace App\Http\Controllers;

use App\Enums\TransactionChannel;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PointOfSaleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('POS/Index', [
            'products' => $this->getProductsData(),
            'categories' => $this->getCategoriesData(),
            'customers' => $this->getCustomersData(),
            'defaultCustomer' => $this->getDefaultCustomerData(),
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'cartItems' => 'required|array|min:1',
            'cartItems.*.id' => 'required|exists:products,id',
            'cartItems.*.quantity' => 'required|numeric|min:1',
            'cartItems.*.unit_price' => 'required|numeric|min:0',
            'cartItems.*.description' => 'required|string',
            'customerId' => 'nullable|exists:customers,id',
            'subtotal' => 'required|numeric',
            'total_discount' => 'required|numeric',
            'total' => 'required|numeric',
            'payments' => 'sometimes|array',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.method' => ['required', Rule::in(['efectivo', 'tarjeta', 'transferencia'])],
            'use_balance' => 'required|boolean',
        ]);

        $user = Auth::user();
        $customer = $validated['customerId'] ? Customer::find($validated['customerId']) : null;
        $totalPaid = collect($validated['payments'])->sum('amount');
        $totalSale = $validated['total'];

        try {
            $transaction = DB::transaction(function () use ($validated, $user, $customer, $totalPaid, $totalSale) {

                // --- Lógica de Saldo y Crédito ---
                $amountFromBalance = 0;
                if ($customer && $validated['use_balance'] && $customer->balance > 0) {
                    $amountFromBalance = min($totalSale, $customer->balance);
                }

                $remainingDue = $totalSale - $totalPaid - $amountFromBalance;

                // --- Validación ---
                if (!$customer) { // Público en General
                    if ($remainingDue > 0.01) { // Pequeño margen para errores de redondeo
                        throw new \Exception('El pago debe ser completo para ventas a Público en General.');
                    }
                } else { // Cliente Registrado
                    if ($remainingDue > $customer->available_credit) {
                        throw new \Exception('El crédito disponible del cliente no es suficiente para cubrir el monto restante.');
                    }
                }

                // 1. Crear la Transacción
                $newTransaction = Transaction::create([
                    'folio' => $this->generateFolio(),
                    'customer_id' => $customer?->id,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    'status' => $remainingDue > 0.01 ? TransactionStatus::PENDING : TransactionStatus::COMPLETED,
                    'channel' => TransactionChannel::POS,
                    'subtotal' => $validated['subtotal'],
                    'total_discount' => $validated['total_discount'],
                    'total_tax' => 0,
                    'currency' => 'MXN',
                    'status_changed_at' => now(),
                ]);

                // 2. Crear Items y Descontar Stock
                foreach ($validated['cartItems'] as $item) {
                    $newTransaction->items()->create([
                        'itemable_id' => $item['id'],
                        'itemable_type' => Product::class,
                        'description' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $item['quantity'] * $item['unit_price'],
                    ]);
                    Product::find($item['id'])->decrement('current_stock', $item['quantity']);
                }

                // 3. Crear Pagos
                foreach ($validated['payments'] as $payment) {
                    $newTransaction->payments()->create([
                        'amount' => $payment['amount'],
                        'payment_method' => $payment['method'],
                        'payment_date' => now(),
                        'status' => 'completado',
                    ]);
                }

                // 4. Actualizar Saldo del Cliente si aplica
                if ($customer) {
                    $totalChargedToBalance = $remainingDue + $amountFromBalance;
                    if ($totalChargedToBalance > 0) {
                        $customer->decrement('balance', $totalChargedToBalance);
                    }
                }

                return $newTransaction;
            });

            return redirect()->back()->with('success', 'Venta registrada con éxito. Folio: ' . $transaction->folio);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error al procesar la venta: ' . $e->getMessage());
        }
    }

    private function getProductsData()
    {
        $branchId = Auth::user()->branch_id;
        return Product::where('branch_id', $branchId)->with('media', 'category:id,name')->get()
            ->map(fn($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->selling_price,
                'stock' => $product->current_stock,
                'category' => $product->category->name ?? 'Sin categoría',
                'image' => $product->getFirstMediaUrl('product-general-images') ?: 'https://placehold.co/400x400/EBF8FF/3182CE?text=' . urlencode($product->name),
                'description' => $product->description,
                'sku' => $product->sku,
                'variants' => new \stdClass(),
            ]);
    }

    private function getCategoriesData()
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Category::where('subscription_id', $subscriptionId)->where('type', 'product')->select('id', 'name')->get();
    }

    private function getCustomersData()
    {
        $branchId = Auth::user()->branch_id;
        $customers = Customer::where('branch_id', $branchId)
            ->select('id', 'name', 'phone', 'balance', 'credit_limit')
            ->orderBy('name')->get();

        // --- SOLUCIÓN: Convertir los valores a números antes de enviarlos ---
        return $customers->map(function ($customer) {
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => (float) $customer->balance,
                'credit_limit' => (float) $customer->credit_limit,
                'available_credit' => (float) $customer->available_credit, // Incluimos el accesor
            ];
        });
    }

    private function getDefaultCustomerData()
    {
        return ['id' => null, 'name' => 'Público en General', 'phone' => '', 'balance' => 0, 'credit_limit' => 0];
    }

    private function generateFolio(): string
    {
        $prefix = strtoupper(substr(Auth::user()->branch->name, 0, 4));
        $date = Carbon::now()->format('Ymd');
        $lastTransaction = Transaction::whereDate('created_at', Carbon::today())->where('branch_id', Auth::user()->branch_id)->latest('id')->first();
        $sequence = $lastTransaction ? (int)substr($lastTransaction->folio, -3) + 1 : 1;
        return $prefix . '-' . $date . '-' . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
}
