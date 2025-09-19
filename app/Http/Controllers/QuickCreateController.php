<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ExpenseCategory;
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
}
