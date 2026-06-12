<?php

namespace App\Http\Controllers;

use App\Enums\TemplateContextType;
use App\Enums\TemplateType;
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
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
        
        $query = Customer::query()
            ->where('branch_id', $user->branch_id)
            ->withSum('layawayItems as layaway_items_quantity_sum', 'quantity');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('company_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('phone', 'LIKE', "%{$searchTerm}%");
            });
        }

        $query->orderBy($request->input('sortField', 'created_at'), $request->input('sortOrder', 'desc'));

        $customers = $query->paginate($request->input('rows', 20))->withQueryString();

        $customers->getCollection()->transform(function ($customer) {
            $customer->append('available_credit');
            return $customer;
        });

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

        DB::transaction(function () use ($validated, $initialBalance, $request) {
            $customer = Customer::create(array_merge($validated, [
                'branch_id' => Auth::user()->branch_id,
                'balance' => 0, 
                'address' => $request->input('address', []),
            ]));

            // Usamos el nuevo método delegado al modelo
            if ($initialBalance != 0) {
                $customer->manualBalanceAdjustment('add', $initialBalance, 'Saldo Inicial registrado al crear cliente.');
            }
        });

        return redirect()->route('customers.index')->with('success', 'Cliente creado con éxito.');
    }

    public function show(Customer $customer): Response
    {
        $customer->load([
            'transactions' => function ($query) {
                $query->with([
                    'items', 
                    'user', 
                    'transactionable' => function (MorphTo $morphTo) {
                        $morphTo->morphWith([
                            \App\Models\ServiceOrder::class => ['items'],
                        ]);
                    }
                ])->orderBy('created_at', 'desc');
            },
            'layawayTransactions' => function ($query) {
                $query->with(['payments', 'items'])->orderBy('created_at', 'desc');
            },
        ]);

        $user = Auth::user();
        
        $availableTemplates = $user->branch->printTemplates()
            ->whereIn('type', [TemplateType::SALE_TICKET, TemplateType::LABEL])
            ->whereIn('context_type', [TemplateContextType::CUSTOMER, TemplateContextType::GENERAL])
            ->get();

        $availableCashRegisters = CashRegister::where('branch_id', $user->branch_id)
            ->where('is_active', true)
            ->where('in_use', false)
            ->get(['id', 'name']);

        $userBankAccounts = (!$user->roles()->exists()) 
            ? $user->branch->bankAccounts()->get() 
            : $user->bankAccounts()->get();

        // Aprovechamos que "Transaction" ya expone total_paid y remaining_due automáticamente
        $formattedLayaways = $customer->layawayTransactions->map(fn ($transaction) => [
            'id' => $transaction->id,
            'folio' => $transaction->folio,
            'created_at' => $transaction->created_at->toDateTimeString(),
            'total_amount' => $transaction->total,
            'total_paid' => $transaction->total_paid, // Reutilizado de Transaction.php
            'pending_amount' => $transaction->remaining_due, // Reutilizado de Transaction.php
            'total_items_quantity' => $transaction->items->sum('quantity'),
            'layaway_expiration_date' => $transaction->layaway_expiration_date,
            'items' => $transaction->items,
        ]);

        return Inertia::render('Customer/Show', [
            'customer' => $customer,
            'historicalMovements' => $customer->historical_movements,
            'availableCashRegisters' => $availableCashRegisters,
            'userBankAccounts' => $userBankAccounts,
            'activeLayaways' => $formattedLayaways, 
            'availableTemplates' => $availableTemplates,
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
        $data = $request->validated();
        if ($request->has('address')) {
            $data['address'] = $request->input('address');
        }

        $customer->update($data);
        return redirect()->route('customers.index')->with('success', 'Cliente actualizado con éxito.');
    }

    public function adjustBalance(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'adjustment_type' => ['required', Rule::in(['add', 'set_total'])],
            'amount' => ['required', 'numeric'],
            'notes' => ['required', 'string', 'max:255'],
        ]);

        // Delegamos al modelo el cálculo matemático y el guardado en BD.
        DB::transaction(fn () => $customer->manualBalanceAdjustment(
            $validated['adjustment_type'],
            $validated['amount'],
            "Ajuste manual: " . $validated['notes']
        ));

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