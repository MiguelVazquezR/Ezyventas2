<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCashRegisterRequest;
use App\Http\Requests\UpdateCashRegisterRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:cash_registers.access', only: ['index', 'show']),
            new Middleware('can:cash_registers.manage', only: ['create', 'store', 'edit', 'update', 'destroy']),
        ];
    }

    /**
     * --- AÑADIDO: Función auxiliar para obtener datos del límite de cajas. ---
     */
    private function getCashRegisterLimitData()
    {
        $subscription = Auth::user()->branch->subscription;
        $currentVersion = $subscription->versions()->latest('start_date')->first();
        $limit = -1; // -1 significa ilimitado
        if ($currentVersion) {
            $limitItem = $currentVersion->items()->where('item_key', 'limit_cash_registers')->first();
            if ($limitItem) {
                $limit = $limitItem->quantity;
            }
        }
        $usage = $subscription->cashRegisters()->count();
        return ['limit' => $limit, 'usage' => $usage];
    }

    public function index(): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $cashRegisters = CashRegister::where('branch_id', $branchId)
            ->with('branch:id,name')
            ->get();

        // --- AÑADIDO: Se pasan los datos del límite a la vista ---
        $limitData = $this->getCashRegisterLimitData();

        return Inertia::render('FinancialControl/CashRegister/Index', [
            'cashRegisters' => $cashRegisters,
            'cashRegisterLimit' => $limitData['limit'],
            'cashRegisterUsage' => $limitData['usage'],
        ]);
    }

    public function create(): Response
    {
        // --- AÑADIDO: Se pasan los datos del límite a la vista ---
        $limitData = $this->getCashRegisterLimitData();

        return Inertia::render('FinancialControl/CashRegister/Create', [
            'cashRegisterLimit' => $limitData['limit'],
            'cashRegisterUsage' => $limitData['usage'],
        ]);
    }

    public function store(StoreCashRegisterRequest $request)
    {
        // --- AÑADIDO: Validación del límite de cajas ---
        $limitData = $this->getCashRegisterLimitData();
        if ($limitData['limit'] !== -1 && $limitData['usage'] >= $limitData['limit']) {
            throw ValidationException::withMessages([
                'limit' => 'Has alcanzado el límite de cajas registradoras de tu plan.'
            ]);
        }

        CashRegister::create(array_merge($request->validated(), [
            'branch_id' => Auth::user()->branch_id,
        ]));
        return redirect()->route('cash-registers.index')->with('success', 'Caja registradora creada con éxito.');
    }

    public function edit(CashRegister $cashRegister): Response
    {
        return Inertia::render('FinancialControl/CashRegister/Edit', [
            'cashRegister' => $cashRegister,
        ]);
    }

    public function update(UpdateCashRegisterRequest $request, CashRegister $cashRegister)
    {
        $cashRegister->update($request->validated());
        return redirect()->route('cash-registers.index')->with('success', 'Caja registradora actualizada con éxito.');
    }

    public function show(CashRegister $cashRegister): Response
    {
        $branch = $cashRegister->branch;

        // CORRECCIÓN DEFINITIVA: Simplificamos la consulta.
        // La relación `payments` es la única fuente de verdad para los pagos recibidos
        // durante esta sesión. Se eliminó `transactions.payments` para evitar confusiones
        // y la posibilidad de contar pagos de otras sesiones.
        $currentSession = $cashRegister->sessions()
            ->where('status', 'abierta')
            ->with([
                'user:id,name',
                'cashMovements',
                'transactions', // Se mantiene por si se necesita mostrar las transacciones de la sesión
                'payments'      // ÚNICA FUENTE DE VERDAD para los pagos de esta sesión.
            ])
            ->first();

        $closedSessions = $cashRegister->sessions()
            ->where('status', 'cerrada')
            ->with('user:id,name')
            ->latest('closed_at')
            ->paginate(10);

        $busyUserIds = CashRegisterSession::where('status', 'abierta')
            ->whereHas('cashRegister.branch.subscription', function ($query) use ($branch) {
                $query->where('id', $branch->subscription_id);
            })
            ->pluck('user_id');

        $branchUsers = $branch->users->map(function ($branchUser) use ($busyUserIds) {
            $branchUser->is_busy = $busyUserIds->contains($branchUser->id);
            return $branchUser;
        });

        return Inertia::render('FinancialControl/CashRegister/Show', [
            'cashRegister' => $cashRegister->load('branch'),
            'currentSession' => $currentSession,
            'closedSessions' => $closedSessions,
            'branchUsers' => $branchUsers,
        ]);
    }

    public function destroy(CashRegister $cashRegister)
    {
        if ($cashRegister->sessions()->exists()) {
            return redirect()->back()->with(['error' => 'No se puede eliminar una caja con sesiones de historial.']);
        }
        $cashRegister->delete();
        return redirect()->route('cash-registers.index')->with('success', 'Caja registradora eliminada.');
    }
}
