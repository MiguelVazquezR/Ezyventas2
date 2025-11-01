<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Asegúrate de importar Auth
use Illuminate\Validation\Rule; // Asegúrate de importar Rule

class BrandController extends Controller
{
    /**
     * Display a listing of the resource.
     * NUEVO: Muestra las marcas de la suscripción del usuario.
     */
    public function index()
    {
        // Obtiene el ID de la suscripción del usuario autenticado
        $subscriptionId = Auth::user()->branch->subscription_id;

        // Carga solo las marcas que pertenecen a esa suscripción
        $brands = Brand::where('subscription_id', $subscriptionId)
            ->latest('created_at') // Ordena de más nueva a más antigua
            ->get();

        // Devuelve las marcas como JSON
        return response()->json($brands);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // No se usa para la API
    }

    /**
     * Store a newly created resource in storage.
     * Esta función la maneja QuickCreateController, la dejamos vacía aquí.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Brand $brand)
    {
        // No se usa para la API
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Brand $brand)
    {
        // No se usa para la API
    }

    /**
     * Update the specified resource in storage.
     * NUEVO: Actualiza el nombre de una marca.
     */
    public function update(Request $request, Brand $brand)
    {
        // 1. Verifica que la marca pertenezca a la suscripción del usuario
        if ($brand->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado para editar esta marca.');
        }

        // 2. Valida los datos de entrada
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                // Asegura que el nombre sea único en esta suscripción,
                // ignorando la propia marca que se está editando.
                Rule::unique('brands')->where(function ($query) {
                    return $query->where('subscription_id', Auth::user()->branch->subscription_id);
                })->ignore($brand->id),
            ]
        ]);

        // 3. Actualiza la marca
        $brand->update($validated);

        // 4. Devuelve la marca actualizada
        return response()->json($brand);
    }

    /**
     * Remove the specified resource from storage.
     * NUEVO: Elimina una marca.
     */
    public function destroy(Brand $brand)
    {
        // 1. Verifica que la marca pertenezca a la suscripción del usuario
        if ($brand->subscription_id !== Auth::user()->branch->subscription_id) {
            abort(403, 'No autorizado para eliminar esta marca.');
        }

        // 2. NUEVA VALIDACIÓN: Verifica si la marca está siendo usada por algún producto
        if ($brand->products()->exists()) {
            // Si está en uso, devuelve un error 422 (Unprocessable Entity)
            // que el frontend puede entender.
            return response()->json([
                'message' => 'No se puede eliminar la marca "' . $brand->name . '" porque ya está asignada a uno o más productos.'
            ], 422);
        }

        // 3. Si no está en uso, la elimina
        $brand->delete();

        // 4. Devuelve una respuesta exitosa sin contenido
        return response()->noContent();
    }
}