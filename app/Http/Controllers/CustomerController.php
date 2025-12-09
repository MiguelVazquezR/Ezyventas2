<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
use App\Enums\TemplateContextType; // <-- Importado
use App\Enums\TemplateType;        // <-- Importado
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\CashRegister;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.access', only: ['index']),
            new Middleware('can:customers.create', only: ['create', 'store']),
            new Middleware('can:customers.see_details', only: ['show', 'printStatement']),
            new Middleware('can:customers.edit', only: ['edit', 'update', 'adjustBalance']),
            new Middleware('can:customers.delete', only: ['destroy', 'batchDestroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = Customer::query()
            ->where('branch_id', $branchId);

        $query->withSum('layawayItems as layaway_items_quantity_sum', 'quantity');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('company_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('phone', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'created_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $customers = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('Customer/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customer/Create');
    }

    public function store(StoreCustomerRequest $request)
    {
        $validated = $request->validated();
        $initialBalance = $validated['initial_balance'] ?? 0;
        unset($validated['initial_balance']);

        DB::transaction(function () use ($validated, $initialBalance) {
            $customer = Customer::create(array_merge($validated, [
                'branch_id' => Auth::user()->branch_id,
                'balance' => $initialBalance, 
            ]));

            if ($initialBalance != 0) {
                $customer->balanceMovements()->create([
                    'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                    'amount' => $initialBalance,
                    'balance_after' => $initialBalance, 
                    'notes' => 'Saldo Inicial registrado al crear cliente.',
                ]);
            }
        });

        return redirect()->route('customers.index')->with('success', 'Cliente creado con éxito.');
    }

   public function show(Customer $customer): Response
    {
        $customer->load([
            'transactions' => fn($query) => $query->orderBy('created_at', 'desc'),
            'layawayTransactions' => function ($query) {
                $query->with(['payments', 'items'])
                      ->orderBy('created_at', 'desc');
            },
        ]);

        $user = Auth::user();
        
        // --- NUEVO: Obtener Plantillas Filtradas ---
        // Obtenemos solo plantillas de TICKET o ETIQUETA que sean de contexto CLIENTE o GENERAL
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::CUSTOMER, TemplateContextType::GENERAL])
            ->get();
        // -------------------------------------------

        $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
            ->where('is_active', true)
            ->where('in_use', false)
            ->get(['id', 'name']);

        $isOwner = !$user->roles()->exists();
        $userBankAccounts = null;

        if ($isOwner) {
            $userBankAccounts = $user->branch->bankAccounts()->get();
        } else {
            $userBankAccounts = $user->bankAccounts()->get();
        }

        $formattedLayaways = $customer->layawayTransactions->map(function ($transaction) {
            $totalPaid = $transaction->payments->sum('amount');
            return [
                'id' => $transaction->id,
                'folio' => $transaction->folio,
                'created_at' => $transaction->created_at->toDateTimeString(),
                'total_amount' => (float) $transaction->total,
                'total_paid' => (float) $totalPaid,
                'pending_amount' => (float) $transaction->total - $totalPaid,
                'total_items_quantity' => $transaction->items->sum('quantity'),
                'layaway_expiration_date' => $transaction->layaway_expiration_date,
            ];
        });

        return Inertia::render('Customer/Show', [
            'customer' => $customer,
            'historicalMovements' => $customer->historical_movements,
            'availableCashRegisters' => $availableCashRegisters,
            'userBankAccounts' => $userBankAccounts,
            'activeLayaways' => $formattedLayaways, 
            'availableTemplates' => $availableTemplates, // <-- Pasamos las plantillas a la vista
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customer/Edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());
        return redirect()->route('customers.index')->with('success', 'Cliente actualizado con éxito.');
    }

    public function adjustBalance(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'adjustment_type' => ['required', Rule::in(['add', 'set_total'])],
            'amount' => ['required', 'numeric'],
            'notes' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($customer, $validated) {
            $currentBalance = $customer->balance;
            $newBalance = 0;
            $adjustmentAmount = 0;
            $notes = "Ajuste manual: " . $validated['notes'];

            if ($validated['adjustment_type'] === 'add') {
                $adjustmentAmount = $validated['amount'];
                $newBalance = $currentBalance + $adjustmentAmount;
            } elseif ($validated['adjustment_type'] === 'set_total') {
                $newBalance = $validated['amount'];
                $adjustmentAmount = $newBalance - $currentBalance; 
            }

            if ($adjustmentAmount == 0) {
                return;
            }

            $customer->update(['balance' => $newBalance]);

            $customer->balanceMovements()->create([
                'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                'amount' => $adjustmentAmount, 
                'balance_after' => $newBalance,
                'notes' => $notes,
            ]);
        });

        return redirect()->back()->with('success', 'Saldo del cliente ajustado con éxito.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->route('customers.index')->with('success', 'Cliente eliminado con éxito.');
    }

    public function batchDestroy(Request $request)
    {
        $request->validate(['ids' => 'required|array']);
        Customer::whereIn('id', $request->input('ids'))->delete();
        return redirect()->route('customers.index')->with('success', 'Clientes seleccionados eliminados.');
    }

    public function printStatement(Customer $customer): Response
    {
        $customer->load(['branch.subscription']);

        return Inertia::render('Customer/PrintStatement', [
            'customer' => $customer,
            'movements' => $customer->historical_movements,
        ]);
    }
}