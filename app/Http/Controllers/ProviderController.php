<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\Product; // <-- AÑADIDO: Necesario para verificar si el proveedor está en uso
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // <-- AÑADIDO: Para la autenticación y autorización
use Illuminate\Validation\Rule; // <-- AÑADIDO: Para reglas de validación

class ProviderController extends Controller
{
    /**
     * Muestra una lista de los proveedores.
     * Se filtra por la suscripción del usuario autenticado.
     */
    public function index()
    {
        // AÑADIDO: Carga solo los proveedores de la suscripción del usuario
        $subscriptionId = Auth::user()->branch->subscription_id;
        $providers = Provider::where('subscription_id', $subscriptionId)
            ->latest('id')
            ->get();

        return response()->json($providers);
    }
    
    /**
     * Actualiza un proveedor específico en la base de datos.
     */
    public function update(Request $request, Provider $provider)
    {
        // AÑADIDO: Lógica de actualización
        $subscriptionId = Auth::user()->branch->subscription_id;

        // 1. Autorización: Asegurarse de que el proveedor pertenece a la suscripción del usuario
        if ($provider->subscription_id !== $subscriptionId) {
            abort(403, 'Acción no autorizada.');
        }

        // 2. Validación: Validar los campos (basado en tu migración)
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:255',
            'address' => 'nullable|array', // Asumiendo que 'address' es un JSON
        ]);

        // 3. Actualización
        $provider->update($validated);

        // 4. Respuesta: Devolver el proveedor actualizado al frontend
        return response()->json($provider);
    }

    /**
     * Elimina un proveedor de la base de datos.
     */
    public function destroy(Provider $provider)
    {
        // AÑADIDO: Lógica de eliminación
        $subscriptionId = Auth::user()->branch->subscription_id;

        // 1. Autorización
        if ($provider->subscription_id !== $subscriptionId) {
            abort(403, 'Acción no autorizada.');
        }

        // 2. Verificación de uso: Comprobar si algún producto está usando este proveedor
        $isUsed = Product::where('provider_id', $provider->id)->exists();

        if ($isUsed) {
            // Si está en uso, devuelve un error 422 (Unprocessable Entity)
            return response()->json([
                'message' => 'Este proveedor está siendo utilizado por uno o más productos y no puede ser eliminado.'
            ], 422);
        }

        // 3. Eliminación
        $provider->delete();

        // 4. Respuesta: Devolver "Sin Contenido"
        return response()->json(null, 204);
    }
}