<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class QuickCreateController extends Controller
{
    /**
     * Almacena una nueva categoría (para Productos o Servicios).
     */
    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['product', 'service'])],
        ]);

        $category = Category::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'subscription_id' => Auth::user()->branch->subscription_id,
        ]);

        return response()->json($category);
    }

    public function storeBrand(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $brand = Brand::create([
            'name' => $validated['name'],
            'subscription_id' => Auth::user()->subscription->id,
        ]);
        return response()->json($brand);
    }

    public function storeProvider(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $provider = Provider::create([
            'name' => $validated['name'],
            'subscription_id' => Auth::user()->subscription->id,
        ]);
        return response()->json($provider);
    }

    public function storeExpenseCategory(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);

        $expenseCategory = ExpenseCategory::create([
            'name' => $validated['name'],
            'subscription_id' => Auth::user()->branch->subscription_id,
        ]);

        return response()->json($expenseCategory);
    }

     public function storeCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        $customer = Customer::create(array_merge($validated, [
            'branch_id' => Auth::user()->branch_id,
        ]));

        // CORRECCIÓN: Asegurarse de que los valores numéricos se devuelvan como números, no como cadenas.
        // El modelo Customer tiene un 'accessor' para available_credit, por lo que podemos acceder a él.
        // Lo convertimos a un array para modificar los tipos antes de enviarlo como JSON.
        $customerData = $customer->toArray();
        $customerData['balance'] = (float) $customer->balance;
        $customerData['credit_limit'] = (float) $customer->credit_limit;
        $customerData['available_credit'] = (float) $customer->available_credit;


        return response()->json($customerData);
    }

    public function storeProduct(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'selling_price' => 'required|numeric|min:0',
            'current_stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:255|unique:products,sku',
        ]);

        $product = Product::create(array_merge($validated, [
            'branch_id' => Auth::user()->branch_id,
            // Puedes añadir otros valores por defecto aquí si es necesario
        ]));

        return response()->json($product);
    }
}