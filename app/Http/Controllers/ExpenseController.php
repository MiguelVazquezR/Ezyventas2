<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
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
            ->with(['user:id,name', 'category:id,name', 'branch:id,name'])
            // Seleccionar explícitamente las columnas de la tabla principal para evitar conflictos de 'id'
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
        // Usar los nombres completos de las columnas para el ordenamiento
        $query->orderBy($sortField === 'user.name' ? 'users.name' : ($sortField === 'category.name' ? 'expense_categories.name' : $sortField), $sortOrder);

        $expenses = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Expense/Index', [
            'expenses' => $expenses,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function show(Expense $expense): Response
    {
        $expense->load(['user', 'category', 'branch', 'activities.causer']);
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
        return Inertia::render('Expense/Create', [
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        $expense = Expense::create(array_merge($request->validated(), [
            'user_id' => Auth::id(),
            'branch_id' => Auth::user()->branch_id,
        ]));

        return redirect()->route('expenses.index')->with('success', 'Gasto creado con éxito.');
    }

    public function edit(Expense $expense): Response
    {
        $subscriptionId = Auth::user()->branch->subscription_id;
        return Inertia::render('Expense/Edit', [
            'expense' => $expense,
            'categories' => ExpenseCategory::where('subscription_id', $subscriptionId)->get(['id', 'name']),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $expense->update($request->validated());
        return redirect()->route('expenses.index')->with('success', 'Gasto actualizado con éxito.');
    }

    public function updateStatus(Expense $expense)
    {
        $newStatus = $expense->status === ExpenseStatus::PAID ? ExpenseStatus::PENDING : ExpenseStatus::PAID;
        $expense->update(['status' => $newStatus]);

        $statusText = $newStatus === ExpenseStatus::PAID ? 'Pagado' : 'Pendiente';
        return redirect()->back()->with('success', "Estatus del gasto actualizado a '{$statusText}'.");
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return redirect()->route('expenses.index')->with('success', 'Gasto eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:expenses,id',
        ]);

        Expense::whereIn('id', $validated['ids'])->delete();
        return redirect()->route('expenses.index')->with('success', 'Gastos seleccionados eliminados con éxito.');
    }
}
