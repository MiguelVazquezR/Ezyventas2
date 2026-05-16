<?php

namespace App\Http\Controllers;

use App\Actions\User\StoreUserAction;
use App\Actions\User\UpdateUserAction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

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

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;

        $query = User::where('branch_id', $user->branch_id)
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

        $limitData = $subscription->getUserLimitData();

        return Inertia::render('User/Index', [
            'users' => $query->paginate($request->input('rows', 20))->withQueryString(),
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
            'userLimit' => $limitData['limit'],
            'userUsage' => $limitData['usage'],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('User/Create', $this->getFormData());
    }

    public function store(Request $request, StoreUserAction $action)
    {
        $subscription = Auth::user()->branch->subscription;
        
        if ($subscription->hasReachedUserLimit()) {
            throw ValidationException::withMessages([
                'limit' => 'Has alcanzado el límite de usuarios de tu plan.'
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:' . User::class,
            'password' => 'required',
            'role_id' => 'required|exists:roles,id',
            'bank_account_ids' => 'nullable|array', 
            'bank_account_ids.*' => 'exists:bank_accounts,id',
        ]);

        $action->execute($validated, Auth::user()->branch_id);

        return redirect()->route('users.index')->with('success', 'Usuario creado con éxito.');
    }

    public function edit(User $user): Response
    {
        $user->load('roles.permissions', 'bankAccounts:id');
        
        return Inertia::render('User/Edit', array_merge($this->getFormData(), [
            'user' => $user,
        ]));
    }

    public function update(Request $request, User $user, UpdateUserAction $action)
    {
        if ($user->branch_id !== Auth::user()->branch_id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'password' => 'nullable',
            'role_id' => 'required|exists:roles,id',
            'bank_account_ids' => 'nullable|array', 
            'bank_account_ids.*' => 'exists:bank_accounts,id', 
        ]);

        $action->execute($user, $validated);

        return redirect()->route('users.index')->with('success', 'Usuario actualizado con éxito.');
    }

    public function destroy(User $user)
    {
        // REFACTOR: Uso del helper isOwner() del modelo User
        if ($user->isOwner()) {
            return redirect()->back()->with('error', 'No se puede eliminar al administrador principal.');
        }

        $user->delete();
        return redirect()->route('users.index')->with('success', 'Usuario eliminado con éxito.');
    }

    public function toggleStatus(User $user)
    {
        if ($user->isOwner()) {
            return redirect()->back()->with('error', 'No se puede desactivar al administrador principal.');
        }

        $user->is_active = !$user->is_active;
        $user->save();
        $status = $user->is_active ? 'activado' : 'desactivado';

        return redirect()->back()->with('success', "Usuario {$status} con éxito.");
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function getFormData(): array
    {
        $user = Auth::user();
        $subscription = $user->branch->subscription;
        $limitData = $subscription->getUserLimitData();

        $roles = Role::where('branch_id', $user->branch_id)->with('permissions')->get()->map(fn($role) => [
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
            ->get()
            ->groupBy('module');

        $bankAccounts = $user->branch->bankAccounts()->get();

        return [
            'roles' => $roles,
            'permissions' => $permissions,
            'userLimit' => $limitData['limit'],
            'userUsage' => $limitData['usage'],
            'bankAccounts' => $bankAccounts,
        ];
    }
}