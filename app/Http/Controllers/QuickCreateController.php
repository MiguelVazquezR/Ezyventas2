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
use App\Models\Role;

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
        // --- MODIFICACIÓN: Añadir validación para los nuevos campos ---
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
        ]);

        // --- MODIFICACIÓN: Pasar todos los datos validados a la creación ---
        $provider = Provider::create(array_merge($validated, [
            'subscription_id' => Auth::user()->subscription->id,
        ]));

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

    public function storeRole(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id' // Valida que cada ID de permiso exista
        ]);

        // CORRECCIÓN: Se añade branch_id para sistemas multisucursal.
        $role = Role::create([
            'name' => $request->name,
            'branch_id' => Auth::user()->branch_id,
            'guard_name' => 'web',
        ]);

        if (!empty($request->permissions)) {
            $role->syncPermissions($request->permissions);
        }

        // Se devuelve el rol con sus permisos para que el frontend lo pueda usar
        return response()->json($role->load('permissions'));
    }
}
