<?php

namespace App\Http\Controllers;

use App\Actions\Expense\StoreExpenseAction;
use App\Actions\Expense\UpdateExpenseAction;
use App\Enums\ExpenseStatus;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\SessionCashMovement;
use App\Services\ActivityLogService;
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
        $branchId = $user->branch_id;
        $isOwner = !$user->roles()->exists();

        $query = Expense::query()
            ->join('users', 'expenses.user_id', '=', 'users.id')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->where('expenses.branch_id', $branchId)
            ->with(['user:id,name', 'category:id,name', 'branch:id,name', 'bankAccount:id,account_name,bank_name'])
            ->select('expenses.*');

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

    public function show(Request $request, Expense $expense, ActivityLogService $activityLogService): Response
    {
        $expense->load(['user', 'category', 'branch', 'bankAccount']);
        
        // REFACTOR: Usamos tu servicio global en lugar de mapear manualmente
        $formattedActivities = $activityLogService->getFormattedActivities($expense, $request, 'Expense');

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

        $userBankAccounts = $isOwner ? $user->branch->bankAccounts()->get() : $user->bankAccounts()->get();

        return Inertia::render('Expense/Create', [
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
            'userBankAccounts' => $userBankAccounts,
        ]);
    }

    public function store(StoreExpenseRequest $request, StoreExpenseAction $action)
    {
        $action->execute($request->validated(), Auth::user());
        return redirect()->route('expenses.index')->with('success', 'Gasto creado con éxito.');
    }

    public function edit(Expense $expense): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;
        $isOwner = !$user->roles()->exists();

        $expense->load('sessionCashMovement');

        if ($isOwner) {
            $bankAccounts = BankAccount::whereHas('branches', fn($q) => $q->where('branch_id', $user->branch_id))->get();
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

    public function update(UpdateExpenseRequest $request, Expense $expense, UpdateExpenseAction $action)
    {
        $action->execute($expense, $request->validated(), Auth::user());
        return redirect()->route('expenses.index')->with('success', 'Gasto actualizado con éxito.');
    }

    public function updateStatus(Request $request, Expense $expense)
    {
        $newStatus = $expense->status === ExpenseStatus::PAID ? ExpenseStatus::PENDING : ExpenseStatus::PAID;

        DB::transaction(function () use ($expense, $newStatus) {
            // Solo los gastos INTERNOS pagan desde el saldo bancario (ver StoreExpenseAction).
            // Los gastos externos nunca deben tocar BankAccount.balance.
            if (! $expense->is_external && $expense->bank_account_id) {
                // REFACTOR: Usar deposit/withdraw del modelo BankAccount
                if ($newStatus === ExpenseStatus::PAID) {
                    BankAccount::find($expense->bank_account_id)?->withdraw($expense->amount);
                } else {
                    BankAccount::find($expense->bank_account_id)?->deposit($expense->amount);
                }
            }
            $expense->update(['status' => $newStatus]);
        });

        $statusText = $newStatus === ExpenseStatus::PAID ? 'Pagado' : 'Pendiente';
        return redirect()->back()->with('success', "Estatus del gasto actualizado a '{$statusText}'.");
    }

    public function destroy(Expense $expense)
    {
        $message = 'Gasto eliminado con éxito.';

        DB::transaction(function () use ($expense, &$message) {
            // REFACTOR: Usando deposit del BankAccount
            if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                $bankAccount = BankAccount::find($expense->bank_account_id);
                if ($bankAccount) {
                    $bankAccount->deposit($expense->amount);
                    $formattedAmount = number_format($expense->amount, 2);
                    $message = "Gasto eliminado. Se regresaron $$formattedAmount a la cuenta '{$bankAccount->account_name}'.";
                }
            }

            $cashMovementId = $expense->session_cash_movement_id;
            $expense->delete();

            if ($cashMovementId) {
                SessionCashMovement::where('id', $cashMovementId)->delete();
                $formattedAmount = number_format($expense->amount, 2);
                $message = "Gasto eliminado. Se restauraron $$formattedAmount a la caja en turno.";
            }
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
        $restoredCash = false;

        DB::transaction(function () use ($validated, &$restoredBalance, &$restoredCash) {
            $expenses = Expense::whereIn('id', $validated['ids'])->get();
            foreach ($expenses as $expense) {
                if ($expense->status === ExpenseStatus::PAID && $expense->bank_account_id) {
                    BankAccount::find($expense->bank_account_id)?->deposit($expense->amount);
                    $restoredBalance = true;
                }

                $cashMovementId = $expense->session_cash_movement_id;
                $expense->delete();

                if ($cashMovementId) {
                    SessionCashMovement::where('id', $cashMovementId)->delete();
                    $restoredCash = true;
                }
            }
        });

        $message = 'Gastos seleccionados eliminados con éxito.';
        if ($restoredBalance || $restoredCash) {
            $message .= ' Se restauró el saldo en las cuentas bancarias y/o cajas afectadas.';
        }

        return redirect()->route('expenses.index')->with('success', $message);
    }
}