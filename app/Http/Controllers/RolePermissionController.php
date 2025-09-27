<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.roles_permissions.access', only: ['index']),
            new Middleware('can:settings.roles_permissions.manage', only: ['store', 'update']),
            new Middleware('can:settings.roles_permissions.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        $branchId = Auth::user()->branch_id;

        // Obtener roles de la sucursal actual, cargando sus permisos
        $roles = Role::where('branch_id', $branchId)
            ->with('permissions:id,name')
            ->get(['id', 'name']);

        // Obtener todos los permisos y agruparlos por módulo
        $permissions = Permission::all()->groupBy('module');

        return Inertia::render('Role/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Role::create([
            'name' => $request->name,
            'branch_id' => Auth::user()->branch_id,
            'guard_name' => 'web',
        ]);

        return redirect()->back()->with('success', 'Rol creado con éxito.');
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        // Asegurarse de que el rol pertenece a la sucursal del usuario
        if ($role->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $permissions = Permission::whereIn('id', $request->permissions)->get();
        $role->syncPermissions($permissions);

        return redirect()->back()->with('success', 'Permisos actualizados con éxito.');
    }

    public function destroy(Role $role)
    {
        // Asegurarse de que el rol pertenece a la sucursal del usuario
        if ($role->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }
        
        // Opcional: Validar que el rol no tenga usuarios asignados
        if ($role->users()->count() > 0) {
            return redirect()->back()->with('error', 'No se puede eliminar un rol con usuarios asignados.');
        }

        $role->delete();
        return redirect()->route('roles.index')->with('success', 'Rol eliminado con éxito.');
    }
}