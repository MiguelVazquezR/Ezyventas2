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

    public function index(): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $cashRegisters = CashRegister::where('branch_id', $branchId)
            ->with('branch:id,name') // Cargar el nombre de la sucursal
            ->get();

        return Inertia::render('FinancialControl/CashRegister/Index', [
            'cashRegisters' => $cashRegisters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('FinancialControl/CashRegister/Create');
    }

    public function store(StoreCashRegisterRequest $request)
    {
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

       // Cargar la sesión activa con sus relaciones
        $currentSession = $cashRegister->sessions()
            ->where('status', 'abierta')
            ->with(['user:id,name', 'cashMovements', 'transactions.payments'])
            ->first();

        $closedSessions = $cashRegister->sessions()
            ->where('status', 'cerrada')
            ->with('user:id,name')
            ->latest('closed_at')
            ->paginate(10);

        // Obtener IDs de usuarios que tienen una sesión abierta en CUALQUIER caja de la suscripción
        $busyUserIds = CashRegisterSession::where('status', 'abierta')
            ->whereHas('cashRegister.branch.subscription', function ($query) use ($branch) {
                $query->where('id', $branch->subscription_id);
            })
            ->pluck('user_id');

        // Obtener todos los usuarios de la sucursal y añadirles un estado
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
            return redirect()->back()->withErrors(['error' => 'No se puede eliminar una caja con sesiones de historial.']);
        }
        $cashRegister->delete();
        return redirect()->route('cash-registers.index')->with('success', 'Caja registradora eliminada.');
    }
}
