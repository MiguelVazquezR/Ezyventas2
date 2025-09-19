<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuickCreateController extends Controller
{
    public function storeCategory(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $category = Category::create([
            'name' => $validated['name'],
            'subscription_id' => Auth::user()->subscription->id,
        ]);
        // Devolvemos el modelo recién creado como JSON
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
