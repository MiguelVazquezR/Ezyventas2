<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use App\Models\Role;

class UserController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:settings.users.access', only: ['index']),
            new Middleware('can:settings.users.create', only: ['create', 'store']),
            new Middleware('can:settings.users.edit', only: ['edit', 'update']),
            new Middleware('can:settings.users.delete', only: ['destroy']),
            new Middleware('can:settings.users.change_status', only: ['toggleStatus']),
        ];
    }

    /**
     * Obtiene los datos del límite de usuarios para la suscripción actual.
     */
    private function getUserLimitData()
    {
        $subscription = Auth::user()->branch->subscription;
        $currentVersion = $subscription->versions()->latest('start_date')->first();
        $limit = -1; // -1 significa ilimitado
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_users')->first();
            if ($limitItem) {
                $limit = $limitItem->quantity;
            }
        }
        $usage = $subscription->users()->count();
        return ['limit' => $limit, 'usage' => $usage];
    }

    public function index(Request $request): Response
    {
        $branchId = Auth::user()->branch_id;

        $query = User::where('branch_id', $branchId)
            ->with('roles:id,name');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $users = $query->paginate($request->input('rows', 20))->withQueryString();

        $limitData = $this->getUserLimitData();

        return Inertia::render('User/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'userLimit' => $limitData['limit'],
            'userUsage' => $limitData['usage'],
        ]);
    }

    public function destroy(User $user)
    {
        if (!$user->roles()->exists()) {
            return redirect()->back()->with('error', 'No se puede eliminar al administrador principal.');
        }

        $user->delete();
        return redirect()->route('users.index')->with('success', 'Usuario eliminado con éxito.');
    }

    public function toggleStatus(User $user)
    {
        if (!$user->roles()->exists()) {
            return redirect()->back()->with('error', 'No se puede desactivar al administrador principal.');
        }

        $user->is_active = !$user->is_active;
        $user->save();
        $status = $user->is_active ? 'activado' : 'desactivado';

        return redirect()->back()->with('success', "Usuario {$status} con éxito.");
    }

    public function create(): Response
    {
        $limitData = $this->getUserLimitData();
        $subscription = Auth::user()->branch->subscription;

        $roles = Role::where('branch_id', Auth::user()->branch_id)->with('permissions')->get()->map(fn($role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'module' => $p->module
            ])->all(),
        ]);
        $availableModuleNames = $subscription->getAvailableModuleNames();
        $permissions = Permission::query()
            ->whereIn('module', $availableModuleNames)
            ->orWhere('module', 'Sistema')
            ->get()->groupBy('module');

        // Obtener las cuentas bancarias de la suscripción
         $bankAccounts = auth()->user()->branch->bankAccounts()->get();

        return Inertia::render('User/Create', [
            'roles' => $roles,
            'permissions' => $permissions,
            'userLimit' => $limitData['limit'],
            'userUsage' => $limitData['usage'],
            'bankAccounts' => $bankAccounts, // Pasar a la vista
        ]);
    }

    public function store(Request $request)
    {
        $limitData = $this->getUserLimitData();
        if ($limitData['limit'] !== -1 && $limitData['usage'] >= $limitData['limit']) {
            throw ValidationException::withMessages([
                'limit' => 'Has alcanzado el límite de usuarios de tu plan.'
            ]);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:' . User::class,
            'password' => 'required',
            'role_id' => 'required|exists:roles,id',
            'bank_account_ids' => 'nullable|array', // Validar
            'bank_account_ids.*' => 'exists:bank_accounts,id', // Validar
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'branch_id' => Auth::user()->branch_id,
            'email_verified_at' => now(),
        ]);

        $role = Role::find($request->role_id);
        $user->assignRole($role);

        // Sincronizar las cuentas bancarias asignadas
        $user->bankAccounts()->sync($request->input('bank_account_ids', []));

        return redirect()->route('users.index')->with('success', 'Usuario creado con éxito.');
    }

    public function edit(User $user): Response
    {
        $user->load('roles.permissions', 'bankAccounts:id'); // Cargar cuentas asignadas
        $subscription = Auth::user()->branch->subscription;

        $roles = Role::where('branch_id', Auth::user()->branch_id)->with('permissions')->get()->map(fn($role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'module' => $p->module
            ])->all(),
        ]);
        $availableModuleNames = $subscription->getAvailableModuleNames();
        $permissions = Permission::query()
            ->whereIn('module', $availableModuleNames)
            ->orWhere('module', 'Sistema')
            ->get()->groupBy('module');

        // Obtener todas las cuentas de la suscripción
        $bankAccounts = $user->branch->bankAccounts()->get();

        return Inertia::render('User/Edit', [
            'user' => $user,
            'roles' => $roles,
            'permissions' => $permissions,
            'bankAccounts' => $bankAccounts, // Todas las cuentas disponibles
        ]);
    }

    public function update(Request $request, User $user)
    {
        if ($user->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => 'nullable',
            'role_id' => 'required|exists:roles,id',
            'bank_account_ids' => 'nullable|array', // Validar
            'bank_account_ids.*' => 'exists:bank_accounts,id', // Validar
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        $role = Role::find($request->role_id);
        $user->syncRoles($role);
        
        // Sincronizar las cuentas bancarias asignadas
        $user->bankAccounts()->sync($request->input('bank_account_ids', []));

        return redirect()->route('users.index')->with('success', 'Usuario actualizado con éxito.');
    }
}
