<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\SessionCashMovementType;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:expenses.access', only: ['index']),
            new Middleware('can:expenses.create', only: ['create', 'store']),
            new Middleware('can:expenses.see_details', only: ['show']),
            new Middleware('can:expenses.edit', only: ['edit', 'update']),
            new Middleware('can:expenses.delete', only: ['destroy', 'batchDestroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = Expense::query()
            ->join('users', 'expenses.user_id', '=', 'users.id')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->where('expenses.branch_id', $branchId)
            // MODIFICACIÓN: Se añade 'bank_name' para tenerlo disponible en la tabla
            ->with(['user:id,name', 'category:id,name', 'branch:id,name', 'bankAccount:id,account_name,bank_name'])
            ->select('expenses.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('expenses.description', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('expenses.folio', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'expense_date');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField === 'user.name' ? 'users.name' : ($sortField === 'category.name' ? 'expense_categories.name' : 'expenses.' . $sortField), $sortOrder);


        $expenses = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Expense/Index', [
            'expenses' => $expenses,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function show(Expense $expense): Response
    {
        // Cargar la relación con la cuenta bancaria
        $expense->load(['user', 'category', 'branch', 'activities.causer', 'bankAccount']);
        $translations = config('log_translations.Expense', []);

        $formattedActivities = $expense->activities->map(function ($activity) use ($translations) {
            $changes = ['before' => [], 'after' => []];
            if (isset($activity->properties['old'])) {
                foreach ($activity->properties['old'] as $key => $value) {
                    $changes['before'][($translations[$key] ?? $key)] = $value;
                }
            }
            if (isset($activity->properties['attributes'])) {
                foreach ($activity->properties['attributes'] as $key => $value) {
                    $changes['after'][($translations[$key] ?? $key)] = $value;
                }
            }
            return [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                'causer' => $activity->causer ? $activity->causer->name : 'Sistema',
                'timestamp' => $activity->created_at->diffForHumans(),
                'changes' => $changes,
            ];
        });

        return Inertia::render('Expense/Show', [
            'expense' => $expense,
            'activities' => $formattedActivities,
        ]);
    }

     public function create(): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $branchId = $user->branch_id;
        
        // Obtiene las cuentas asignadas a la sucursal actual y carga la información pivote (is_favorite).
        $bankAccounts = BankAccount::whereHas('branches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->with(['branches' => function ($query) use ($branchId) {
                // Limita la carga de la relación a solo la sucursal actual para acceder a 'pivot'
                $query->where('branch_id', $branchId);
            }])
            ->get();
        
        $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
            ->where('is_active', true)
            ->where('in_use', false)
            ->get(['id', 'name']);

        return Inertia::render('Expense/Create', [
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'bankAccounts' => $bankAccounts,
            'availableCashRegisters' => $availableCashRegisters,
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        DB::transaction(function () use ($request) {
            $user = Auth::user();
            $validated = $request->validated();
            $takeFromCashRegister = $validated['take_from_cash_register'] ?? false;

            // Crear el gasto principal
            $expense = Expense::create(array_merge($validated, [
                'user_id' => $user->id,
                'branch_id' => $user->branch_id,
            ]));

            // Lógica para cuentas bancarias (se mantiene)
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    $bankAccount->decrement('balance', $expense->amount);
                }
            }

            // --- NUEVA LÓGICA: REGISTRAR SALIDA DE EFECTIVO DE CAJA ---
            if (
                $expense->payment_method->value === 'efectivo' &&
                $expense->status === ExpenseStatus::PAID &&
                $takeFromCashRegister
            ) {
                $activeSession = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->latest('opened_at')
                    ->first();

                if (!$activeSession) {
                    throw ValidationException::withMessages([
                        'take_from_cash_register' => 'No se encontró una sesión de caja activa para registrar la salida de efectivo.',
                    ]);
                }

                $movement = $activeSession->cashMovements()->create([
                    'type' => SessionCashMovementType::OUTFLOW,
                    'amount' => $expense->amount,
                    'description' => "Gasto: " . ($expense->folio ?: $expense->description),
                ]);

                $expense->update(['session_cash_movement_id' => $movement->id]);
            }
        });

        return redirect()->route('expenses.index')->with('success', 'Gasto creado con éxito.');
    }

     public function edit(Expense $expense): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $branchId = $user->branch_id;
        
        $expense->load('sessionCashMovement');

        $bankAccounts = BankAccount::whereHas('branches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->with(['branches' => function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            }])
            ->get();
            
        $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
            ->where('is_active', true)
            ->where('in_use', false)
            ->get(['id', 'name']);

        return Inertia::render('Expense/Edit', [
            'expense' => $expense,
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'bankAccounts' => $bankAccounts,
            'availableCashRegisters' => $availableCashRegisters,
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        DB::transaction(function () use ($request, $expense) {
            $validated = $request->validated();
            $user = Auth::user();
            $takeFromCashRegister = $validated['take_from_cash_register'] ?? false;
            
            // --- 1. REVERTIR ESTADO ANTERIOR ---
            // Revertir movimiento de caja si existía
            if ($expense->sessionCashMovement) {
                $expense->sessionCashMovement->delete();
            }
            // Revertir saldo de cuenta bancaria si estaba pagado desde una
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $oldBankAccount = BankAccount::find($expense->bank_account_id);
                if ($oldBankAccount) {
                    $oldBankAccount->increment('balance', $expense->amount);
                }
            }

            // --- 2. ACTUALIZAR EL GASTO ---
            $expense->update($validated);
            // Asegurarse de que el enlace al movimiento se borra antes de crear uno nuevo
            $expense->session_cash_movement_id = null; 
            $expense->save();

            // --- 3. APLICAR NUEVO ESTADO ---
            // Aplicar nuevo saldo a cuenta bancaria
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $newBankAccount = BankAccount::find($expense->bank_account_id);
                if ($newBankAccount) {
                    $newBankAccount->decrement('balance', $expense->amount);
                }
            }
            // Crear nuevo movimiento de caja si es necesario
            if (
                $expense->payment_method->value === 'efectivo' &&
                $expense->status === ExpenseStatus::PAID &&
                $takeFromCashRegister
            ) {
                $activeSession = CashRegisterSession::where('status', CashRegisterSessionStatus::OPEN)
                    ->whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                    ->latest('opened_at')
                    ->first();
                
                if (!$activeSession) {
                    throw ValidationException::withMessages([
                        'take_from_cash_register' => 'No hay una sesión de caja activa para registrar la salida de efectivo.',
                    ]);
                }

                $movement = $activeSession->cashMovements()->create([
                    'type' => SessionCashMovementType::OUTFLOW,
                    'amount' => $expense->amount,
                    'description' => "Gasto (act.): " . ($expense->folio ?: $expense->description),
                ]);

                $expense->update(['session_cash_movement_id' => $movement->id]);
            }
        });
        
        return redirect()->route('expenses.index')->with('success', 'Gasto actualizado con éxito.');
    }

    public function updateStatus(Request $request, Expense $expense)
    {
        $newStatus = $expense->status === ExpenseStatus::PAID ? ExpenseStatus::PENDING : ExpenseStatus::PAID;

        DB::transaction(function () use ($expense, $newStatus) {
            $expense->update(['status' => $newStatus]);

            if ($expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    // Si el nuevo estado es PAGADO, se resta del saldo.
                    if ($newStatus === ExpenseStatus::PAID) {
                        $bankAccount->decrement('balance', $expense->amount);
                    }
                    // Si el nuevo estado es PENDIENTE (antes era pagado), se devuelve el dinero.
                    else {
                        $bankAccount->increment('balance', $expense->amount);
                    }
                }
            }
        });

        $statusText = $newStatus === ExpenseStatus::PAID ? 'Pagado' : 'Pendiente';
        return redirect()->back()->with('success', "Estatus del gasto actualizado a '{$statusText}'.");
    }

    public function destroy(Expense $expense)
    {
        DB::transaction(function () use ($expense) {
            // Si el gasto estaba pagado desde una cuenta, restaurar el saldo.
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    $bankAccount->increment('balance', $expense->amount);
                }
            }
            $expense->delete();
        });

        return redirect()->route('expenses.index')->with('success', 'Gasto eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:expenses,id',
        ]);

        DB::transaction(function () use ($validated) {
            $expenses = Expense::whereIn('id', $validated['ids'])->get();
            foreach ($expenses as $expense) {
                if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                    $bankAccount = BankAccount::find($expense->bank_account_id);
                    if ($bankAccount) {
                        $bankAccount->increment('balance', $expense->amount);
                    }
                }
                $expense->delete();
            }
        });

        return redirect()->route('expenses.index')->with('success', 'Gastos seleccionados eliminados con éxito.');
    }
}