<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterSessionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:cash_registers_sessions.access', only: ['index', 'print', 'show']),
        ];
    }

    /**
     * Muestra una lista paginada de todas las sesiones de caja cerradas.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        // SOLUCIÓN: Usar JOINs para permitir el ordenamiento y la búsqueda en tablas relacionadas
        $query = CashRegisterSession::query()
            ->join('users', 'cash_register_sessions.user_id', '=', 'users.id')
            ->join('cash_registers', 'cash_register_sessions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_register_sessions.status', CashRegisterSessionStatus::CLOSED)
            ->whereHas('cashRegister.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            })
            ->with(['user:id,name', 'cashRegister:id,name'])
            ->select('cash_register_sessions.*'); // Evitar conflictos de columnas 'id'

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('users.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('cash_registers.name', 'LIKE', "%{$searchTerm}%"); // <-- Búsqueda por caja añadida
            });
        }

        $sortField = $request->input('sortField', 'closed_at');
        $sortOrder = $request->input('sortOrder', 'desc');
        // Usar nombres de columna completos para el ordenamiento
        $sortColumn = match ($sortField) {
            'user.name' => 'users.name',
            'cash_register.name' => 'cash_registers.name',
            default => $sortField,
        };
        $query->orderBy($sortColumn, $sortOrder);

        $sessions = $query->paginate($request->input('rows', 20))->withQueryString();

        return Inertia::render('FinancialControl/CashRegisterSession/Index', [
            'sessions' => $sessions,
            'filters' => $request->only(['search', 'sortField', 'sortOrder']),
        ]);
    }

    /**
     * Muestra los detalles de una sesión de caja específica.
     */
     public function show(CashRegisterSession $cashRegisterSession): Response
    {
        $cashRegisterSession->load([
            'user:id,name',
            'cashRegister:id,name',
            'payments.transaction:id,folio', 
            'cashMovements'
        ]);
        
        // El cálculo ahora es mucho más simple y preciso
        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $sessionTotals = [
            'cash_total' => $paymentTotals['efectivo'] ?? 0,
            'card_total' => $paymentTotals['tarjeta'] ?? 0,
            'transfer_total' => $paymentTotals['transferencia'] ?? 0,
        ];

        return Inertia::render('FinancialControl/CashRegisterSession/Show', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $sessionTotals,
        ]);
    }

    /**
     * Inicia una nueva sesión de caja (Abre la caja).
     */
    public function store(StoreCashRegisterSessionRequest $request)
    {
        $cashRegister = CashRegister::findOrFail($request->input('cash_register_id'));
        $user = User::findOrFail($request->input('user_id'));

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['error' => 'Esta caja ya está en uso.']);
        }

        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with(['error' => 'Este usuario ya tiene una sesión activa.']);
        }

        DB::transaction(function () use ($request, $cashRegister, $user) {
            $cashRegister->sessions()->create([
                'user_id' => $user->id,
                'opening_cash_balance' => $request->input('opening_cash_balance'),
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);
            $cashRegister->update(['in_use' => true]);
        });

        return redirect()->back()->with('success', 'La caja ha sido abierta con éxito.');
    }

    /**
     * Cierra una sesión de caja existente (Realiza el corte).
     */
    public function update(UpdateCashRegisterSessionRequest $request, CashRegisterSession $cashRegisterSession)
    {
        DB::transaction(function () use ($request, $cashRegisterSession) {
            $validated = $request->validated();
            // Ahora tomamos todos los pagos en efectivo registrados en ESTA sesión,
            // sin importar de qué transacción provengan.
            $cashSales = $cashRegisterSession->payments()
                ->where('payment_method', 'efectivo')
                ->where('status', 'completado')
                ->sum('amount');

            $inflows = $cashRegisterSession->cashMovements()->where('type', 'ingreso')->sum('amount');
            $outflows = $cashRegisterSession->cashMovements()->where('type', 'egreso')->sum('amount');

            $calculatedTotal = $cashRegisterSession->opening_cash_balance + $cashSales + $inflows - $outflows;
            $difference = $validated['closing_cash_balance'] - $calculatedTotal;

            $cashRegisterSession->update([
                'closing_cash_balance' => $validated['closing_cash_balance'],
                'calculated_cash_total' => $calculatedTotal,
                'cash_difference' => $difference,
                'notes' => $validated['notes'],
                'status' => CashRegisterSessionStatus::CLOSED,
                'closed_at' => now(),
            ]);

            $cashRegisterSession->cashRegister->update(['in_use' => false]);
        });

        return redirect()->back()->with('success', 'Corte de caja realizado con éxito.');
    }

    /**
     * Muestra una versión imprimible de la sesión de caja.
     */
    public function print(CashRegisterSession $cashRegisterSession): Response
    {
        $cashRegisterSession->load([
            'user:id,name',
            'cashRegister.branch.subscription',
            'transactions.payments',
            'cashMovements'
        ]);

        $transactionIds = $cashRegisterSession->transactions->pluck('id');
        $paymentTotals = Payment::whereIn('transaction_id', $transactionIds)
            ->where('status', 'completado')
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $sessionTotals = [
            'cash_total' => $paymentTotals['efectivo'] ?? 0,
            'card_total' => $paymentTotals['tarjeta'] ?? 0,
            'transfer_total' => $paymentTotals['transferencia'] ?? 0,
        ];

        return Inertia::render('FinancialControl/CashRegisterSession/PrintReport', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $sessionTotals,
        ]);
    }
}