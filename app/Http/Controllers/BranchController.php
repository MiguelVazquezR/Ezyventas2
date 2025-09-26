<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function store(Request $request)
    {
        $subscription = Auth::user()->branch->subscription;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
        ]);

        $subscription->branches()->create($validated);

        return redirect()->back()->with('success', 'Sucursal creada con éxito.');
    }

    public function update(Request $request, Branch $branch)
    {
        if ($branch->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
        ]);

        $branch->update($validated);

        return redirect()->back()->with('success', 'Sucursal actualizada con éxito.');
    }

    public function destroy(Branch $branch)
    {
        if ($branch->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403);
        }

        // Regla de negocio: No se puede eliminar la sucursal principal
        if ($branch->is_main) {
            return redirect()->back()->with('error', 'No se puede eliminar la sucursal principal.');
        }

        // Opcional: Validar si la sucursal tiene datos asociados (usuarios, ventas, etc.)
        if ($branch->users()->exists() || $branch->transactions()->exists()) {
           return redirect()->back()->with('error', 'No se puede eliminar una sucursal con datos asociados.');
        }
        
        $branch->delete();

        return redirect()->back()->with('success', 'Sucursal eliminada con éxito.');
    }
}