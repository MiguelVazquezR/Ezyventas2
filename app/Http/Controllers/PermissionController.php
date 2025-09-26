<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('permissions', 'name')],
            'description' => 'required|string|max:255',
            'module' => 'required|string|max:255',
        ]);

        Permission::create($validated + ['guard_name' => 'web']);

        return redirect()->back()->with('success', 'Permiso creado con éxito.');
    }

    public function update(Request $request, Permission $permission)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('permissions', 'name')->ignore($permission->id)],
            'description' => 'required|string|max:255',
            'module' => 'required|string|max:255',
        ]);

        $permission->update($validated);

        return redirect()->back()->with('success', 'Permiso actualizado con éxito.');
    }

    public function destroy(Permission $permission)
    {
        // Opcional: Verificar si el permiso está en uso
        if ($permission->roles()->count() > 0) {
            return redirect()->back()->with('error', 'No se puede eliminar un permiso que está asignado a uno o más roles.');
        }
        
        $permission->delete();

        return redirect()->back()->with('success', 'Permiso eliminado con éxito.');
    }
}