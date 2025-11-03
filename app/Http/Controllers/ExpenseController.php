<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
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
        $isOwner = !$user->roles()->exists(); // Propietario de la suscripción no tiene roles

        $query = Expense::query()
            ->join('users', 'expenses.user_id', '=', 'users.id')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->where('expenses.branch_id', $branchId)
            ->with(['user:id,name', 'category:id,name', 'branch:id,name', 'bankAccount:id,account_name,bank_name'])
            ->select('expenses.*');

        // Si el usuario no es el dueño Y no tiene permiso para ver todos los gastos,
        // solo se le muestran los que ha registrado él mismo.
        if (!$isOwner && !$user->can('expenses.see_all')) {
            $query->where('expenses.user_id', $user->id);
        }

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
        $isOwner = !$user->roles()->exists();

        if ($isOwner) {
            $userBankAccounts = $user->branch->bankAccounts()->get();
        } else {
            $userBankAccounts = $user->bankAccounts()->get();
        }

        return Inertia::render('Expense/Create', [
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'userBankAccounts' => $userBankAccounts,
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
                    'user_id' => $user->id
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
        $isOwner = !$user->roles()->exists();

        $expense->load('sessionCashMovement');

        // Si es propietario, obtiene todas las cuentas de la sucursal.
        // Si no, solo las que tiene asignadas.
        if ($isOwner) {
            $bankAccounts = BankAccount::whereHas('branches', function ($query) use ($user) {
                $query->where('branch_id', $user->branch_id);
            })->get();
        } else {
            $bankAccounts = $user->bankAccounts()->get();
        }

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
        // Cargamos la relación del movimiento de caja original ANTES de la transacción
        $expense->load('sessionCashMovement');
        $originalMovement = $expense->sessionCashMovement;

        // Guardamos los valores originales para la lógica de reversión
        $originalAmount = $expense->amount;
        $originalStatus = $expense->status;
        $originalBankAccountId = $expense->bank_account_id;

        DB::transaction(function () use ($request, $expense, $originalMovement, $originalAmount, $originalStatus, $originalBankAccountId) {
            $validated = $request->validated();
            $user = Auth::user();

            // --- Definir el nuevo estado ---
            $newAmount = $validated['amount'];
            $newStatus = ExpenseStatus::from($validated['status']); // Asegurar que sea Enum
            $newPaymentMethod = PaymentMethod::from($validated['payment_method']); // Asegurar que sea Enum
            $newBankAccountId = $validated['bank_account_id'] ?? null;
            $takeFromCashRegister = $validated['take_from_cash_register'] ?? false;

            // --- 1. REVERTIR SALDO BANCARIO (si aplica) ---
            // Revertir saldo de cuenta bancaria si estaba pagado desde una
            if ($originalStatus === ExpenseStatus::PAID && $originalBankAccountId) {
                $oldBankAccount = BankAccount::find($originalBankAccountId);
                if ($oldBankAccount) {
                    $oldBankAccount->increment('balance', $originalAmount);
                }
            }

            // --- 2. ACTUALIZAR EL GASTO ---
            // Actualizamos el gasto con todos los datos validados
            // PERO, reseteamos el 'session_cash_movement_id' por ahora.
            // Se asignará correctamente en el paso 4 si es necesario.
            $validated['session_cash_movement_id'] = null;
            $expense->update($validated);

            // --- 3. APLICAR NUEVO SALDO BANCARIO (si aplica) ---
            if ($newStatus === ExpenseStatus::PAID && $newBankAccountId) {
                $newBankAccount = BankAccount::find($newBankAccountId);
                if ($newBankAccount) {
                    $newBankAccount->decrement('balance', $newAmount);
                }
            }

            // --- 4. LÓGICA DE MOVIMIENTO DE CAJA (El núcleo del cambio) ---
            $isCashWithdrawal = $newPaymentMethod === PaymentMethod::CASH &&
                $newStatus === ExpenseStatus::PAID &&
                $takeFromCashRegister;

            if ($isCashWithdrawal) {
                // Caso 1: El nuevo estado es "Retiro de Caja"

                if ($originalMovement) {
                    // 1.1: Ya existía un movimiento. Lo ACTUALIZAMOS.
                    // No se crea uno nuevo, no se cambia de sesión.
                    $originalMovement->update([
                        'amount' => $newAmount,
                        'description' => "Gasto (actualizado): " . ($expense->folio ?: $expense->description),
                        'user_id' => $user->id, // Actualizar por si otro usuario edita
                    ]);
                    // Re-asignamos el ID del movimiento al gasto
                    // $expense->update(['session_cash_movement_id' => $originalMovement->id]);
                } else {
                    // 1.2: No existía movimiento. Creamos uno NUEVO en la SESIÓN DEL DÍA DEL GASTO.
                    $expenseDate = $expense->expense_date; // Carbon date

                    // Buscamos una sesión (abierta o cerrada) que cubra la fecha del gasto
                    $session = CashRegisterSession::whereHas('cashRegister', fn($q) => $q->where('branch_id', $user->branch_id))
                        ->where('opened_at', '<=', $expenseDate->endOfDay()) // Que abriera antes de terminar el día
                        // ->where(function ($query) use ($expenseDate) {
                        //     $query->where('closed_at', '>=', $expenseDate->startOfDay()) // Y cerrara después de empezar el día
                        //         ->orWhereNull('closed_at'); // O que siga abierta
                        // })
                        ->latest('opened_at')
                        ->first();

                    if (!$session) {
                        // Si no se encuentra, se lanza error. No se puede crear el movimiento.
                        throw ValidationException::withMessages([
                            'take_from_cash_register' => 'No se encontró una sesión de caja (abierta o cerrada) para la fecha del gasto: ' . $expenseDate->format('d/m/Y') . '. No se puede registrar la salida de efectivo.',
                        ]);
                    }

                    $movement = $session->cashMovements()->create([
                        'type' => SessionCashMovementType::OUTFLOW,
                        'amount' => $newAmount,
                        'description' => "Gasto (creado en actualización): " . ($expense->folio ?: $expense->description),
                        'user_id' => $user->id
                    ]);
                    $expense->update(['session_cash_movement_id' => $movement->id]);
                }
            } else {
                // Caso 2: El nuevo estado NO es "Retiro de Caja"

                if ($originalMovement) {
                    // 2.1: Había un movimiento original. Ahora debe BORRARSE.
                    $originalMovement->delete();
                    // El 'session_cash_movement_id' del gasto ya se seteó en null (paso 2).
                }
                // 2.2: No había movimiento original y tampoco hay uno nuevo. No se hace nada.
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
        $message = 'Gasto eliminado con éxito.';

        DB::transaction(function () use ($expense, &$message) {
            // Si el gasto estaba pagado desde una cuenta, restaurar el saldo.
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    $bankAccount->increment('balance', $expense->amount);
                    $formattedAmount = number_format($expense->amount, 2);
                    $message = "Gasto eliminado. Se regresaron $$formattedAmount a la cuenta '{$bankAccount->account_name}'.";
                }
            }
            $expense->delete();
        });

        return redirect()->route('expenses.index')->with('success', $message);
    }

    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:expenses,id',
        ]);

        $restoredBalance = false;

        DB::transaction(function () use ($validated, &$restoredBalance) {
            $expenses = Expense::whereIn('id', $validated['ids'])->get();
            foreach ($expenses as $expense) {
                if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                    $bankAccount = BankAccount::find($expense->bank_account_id);
                    if ($bankAccount) {
                        $bankAccount->increment('balance', $expense->amount);
                        $restoredBalance = true;
                    }
                }
                $expense->delete();
            }
        });

        $message = 'Gastos seleccionados eliminados con éxito.';
        if ($restoredBalance) {
            $message .= ' Se restauró el saldo de las cuentas bancarias afectadas.';
        }

        return redirect()->route('expenses.index')->with('success', $message);
    }
}
