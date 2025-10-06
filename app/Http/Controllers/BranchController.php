<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function store(Request $request)
    {
        $subscription = Auth::user()->branch->subscription;

        // ---Validación del límite de sucursales ---
        $currentVersion = $subscription->versions()->latest('start_date')->first();
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_branches')->first();
            $limit = $limitItem ? $limitItem->quantity : 0;
            $currentCount = $subscription->branches()->count();

            // Si el límite no es ilimitado (-1) y la cuenta actual es mayor o igual al límite
            if ($limit !== -1 && $currentCount >= $limit) {
                // Lanza una excepción de validación que Inertia puede mostrar
                throw ValidationException::withMessages([
                    'limit' => 'Has alcanzado el límite de sucursales de tu plan.'
                ]);
            }
        }

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