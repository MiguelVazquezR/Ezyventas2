<?php

namespace App\Http\Controllers;

use App\Enums\CustomerBalanceMovementType;
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
            // --- MODIFICADO: Añadido printStatement a los permisos de 'see_details' ---
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

        // Remover 'initial_balance' si existe, para que no falle al crear el cliente
        // (ya que no es una columna en la BD, 'balance' sí lo es).
        unset($validated['initial_balance']);

        DB::transaction(function () use ($validated, $initialBalance) {
            
            // 1. Crear el cliente y asignar su saldo inicial
            $customer = Customer::create(array_merge($validated, [
                'branch_id' => Auth::user()->branch_id,
                'balance' => $initialBalance, // Asignar el saldo inicial
            ]));

            // 2. Si el saldo inicial es diferente de cero, crear el movimiento
            if ($initialBalance != 0) {
                $customer->balanceMovements()->create([
                    'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                    'amount' => $initialBalance,
                    'balance_after' => $initialBalance, // Es el primer movimiento
                    'notes' => 'Saldo Inicial registrado al crear cliente.',
                ]);
            }
        });

        return redirect()->route('customers.index')->with('success', 'Cliente creado con éxito.');
    }

    public function show(Customer $customer): Response
    {
        // Se cargan las transacciones por separado para la primera tabla.
        $customer->load([
            'transactions' => fn($query) => $query->orderBy('created_at', 'desc'),
        ]);

        // Se obtienen las cajas registradoras disponibles para el modal de apertura de sesión.
        $user = Auth::user();
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

        return Inertia::render('Customer/Show', [
            'customer' => $customer,
            // Se pasa el nuevo historial de movimientos calculado a través del accesor en el modelo Customer.
            'historicalMovements' => $customer->historical_movements,
            'availableCashRegisters' => $availableCashRegisters,
            'userBankAccounts' => $userBankAccounts,
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

    /**
     * --- método para ajuste manual de saldo ---
     */
    public function adjustBalance(Request $request, Customer $customer)
    {
        // Validar la entrada
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
                // Modo: Sumar/Restar Monto
                // 'amount' es el monto a sumar/restar (ej: -50 o 100)
                $adjustmentAmount = $validated['amount'];
                $newBalance = $currentBalance + $adjustmentAmount;
            } elseif ($validated['adjustment_type'] === 'set_total') {
                // Modo: Establecer Saldo Total
                // 'amount' es el nuevo saldo deseado (ej: 0 o -200)
                $newBalance = $validated['amount'];
                $adjustmentAmount = $newBalance - $currentBalance; // Calculamos la diferencia
            }

            // Si no hay cambio, no hacemos nada
            if ($adjustmentAmount == 0) {
                return;
            }

            // Actualizar el saldo del cliente
            $customer->update(['balance' => $newBalance]);

            // Crear el movimiento en el historial
            $customer->balanceMovements()->create([
                'type' => CustomerBalanceMovementType::MANUAL_ADJUSTMENT,
                'amount' => $adjustmentAmount, // Registramos la *diferencia*
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

    /**
     * --- NUEVO: Método para generar el estado de cuenta imprimible ---
     */
    public function printStatement(Customer $customer): Response
    {
        // Cargar la sucursal y la suscripción para los detalles del encabezado
        $customer->load(['branch.subscription']);

        return Inertia::render('Customer/PrintStatement', [
            'customer' => $customer,
            // Usar el accesor que ya define la lógica del historial
            'movements' => $customer->historical_movements,
        ]);
    }
}