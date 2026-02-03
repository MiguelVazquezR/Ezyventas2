<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SwitchBranchController extends Controller
{
    /**
     * Actualiza la sucursal activa del usuario.
     */
    public function update(Branch $branch)
    {
        $user = Auth::user();

        // Verificación de seguridad: el usuario solo puede cambiar a sucursales de su propia suscripción.
        // EXCEPCIÓN: Si el usuario es el Super Admin (ID 1), permitimos el cambio entre suscripciones.
        if ($user->id !== 1 && $user->branch->subscription_id !== $branch->subscription_id) {
            abort(403, 'No tienes permiso para cambiar a esta sucursal.');
        }

        // Se actualiza la sucursal en el modelo del usuario.
        $user->branch_id = $branch->id;
        $user->save();

        // Se redirige al dashboard para que toda la aplicación se actualice con el nuevo contexto.
        return redirect()->route('dashboard')->with('success', "Cambiado a la sucursal: {$branch->name}");
    }
}