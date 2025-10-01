<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $subscriptionId = $user->branch->subscription_id;

        $query = Expense::query()
            ->join('users', 'expenses.user_id', '=', 'users.id')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->whereHas('branch.subscription', function ($q) use ($subscriptionId) {
                $q->where('id', $subscriptionId);
            })
            ->with(['user:id,name', 'category:id,name', 'branch:id,name', 'bankAccount:id,account_name'])
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
        $subscriptionId = Auth::user()->branch->subscription_id;
        
        $bankAccounts = BankAccount::where('subscription_id', $subscriptionId)
            ->get(['id', 'account_name', 'bank_name', 'account_number']);

        return Inertia::render('Expense/Create', [
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        DB::transaction(function () use ($request) {
            $validated = $request->validated();

            $expense = Expense::create(array_merge($validated, [
                'user_id' => Auth::id(),
                'branch_id' => Auth::user()->branch_id,
            ]));

            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    $bankAccount->decrement('balance', $expense->amount);
                }
            }
        });

        return redirect()->route('expenses.index')->with('success', 'Gasto creado con éxito.');
    }

    public function edit(Expense $expense): Response
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        
        $bankAccounts = BankAccount::where('subscription_id', $subscriptionId)
            ->get(['id', 'account_name', 'bank_name', 'account_number']);

        return Inertia::render('Expense/Edit', [
            'expense' => $expense,
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'bankAccounts' => $bankAccounts,
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        DB::transaction(function () use ($request, $expense) {
            $validated = $request->validated();
            
            // Guardar estado anterior para la lógica de saldo
            $oldAmount = $expense->amount;
            $oldStatus = $expense->status;
            $oldBankAccountId = $expense->bank_account_id;

            // Revertir el monto del gasto anterior si estaba pagado desde una cuenta
            if ($oldStatus === ExpenseStatus::PAID && $oldBankAccountId) {
                $oldBankAccount = BankAccount::find($oldBankAccountId);
                if ($oldBankAccount) {
                    $oldBankAccount->increment('balance', $oldAmount);
                }
            }

            // Actualizar el gasto con los nuevos datos
            $expense->update($validated);
            
            // Aplicar el nuevo monto del gasto si está pagado desde una cuenta
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $newBankAccount = BankAccount::find($expense->bank_account_id);
                if ($newBankAccount) {
                    $newBankAccount->decrement('balance', $expense->amount);
                }
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