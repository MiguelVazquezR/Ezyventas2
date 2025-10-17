<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashRegisterSessionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:cash_registers.sessions.access', only: ['index', 'print', 'show']),
        ];
    }

    /**
     * Muestra una lista paginada de todas las sesiones de caja cerradas.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = CashRegisterSession::query()
            ->join('users', 'cash_register_sessions.user_id', '=', 'users.id') // El user_id es el opener
            ->join('cash_registers', 'cash_register_sessions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_register_sessions.status', CashRegisterSessionStatus::CLOSED)
            ->whereHas('cashRegister.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            })
            // CORRECCIÓN: Se carga la relación 'opener' en lugar de 'user'
            ->with(['opener:id,name', 'cashRegister:id,name'])
            ->select('cash_register_sessions.*');

        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('users.name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('cash_registers.name', 'LIKE', "%{$searchTerm}%");
            });
        }

        $sortField = $request->input('sortField', 'closed_at');
        $sortOrder = $request->input('sortOrder', 'desc');

        // La lógica de ordenamiento con el JOIN a 'users' sigue siendo válida para el 'opener'.
        $sortColumn = match ($sortField) {
            'opener.name' => 'users.name', // Se ajusta el nombre del filtro si es necesario en el frontend
            'cash_register.name' => 'cash_registers.name',
            default => 'cash_register_sessions.' . $sortField,
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
        // CORRECCIÓN: Se cargan las relaciones correctas ('opener', 'users') y las anidadas necesarias.
        $cashRegisterSession->load([
            'opener:id,name',
            'users:id,name',
            'cashRegister:id,name',
            'payments',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $sessionTotals = [
            'cash_total' => $paymentTotals['efectivo'] ?? 0,
            'card_total' => $paymentTotals['tarjeta'] ?? 0,
            'transfer_total' => $paymentTotals['transferencia'] ?? 0,
            'balance_total' => $paymentTotals['saldo'] ?? 0,
        ];

        return Inertia::render('FinancialControl/CashRegisterSession/Show', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $sessionTotals,
        ]);
    }

    /**
     * Muestra una versión imprimible de la sesión de caja.
     */
    public function print(CashRegisterSession $cashRegisterSession): Response
    {
        // CORRECCIÓN: Se carga 'opener' en lugar de 'user' y se añaden otras relaciones para un reporte completo.
        $cashRegisterSession->load([
            'opener:id,name',
            'users:id,name',
            'cashRegister.branch.subscription',
            'payments',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', 'completado')
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $sessionTotals = [
            'cash_total' => $paymentTotals['efectivo'] ?? 0,
            'card_total' => $paymentTotals['tarjeta'] ?? 0,
            'transfer_total' => $paymentTotals['transferencia'] ?? 0,
            'balance_total' => $paymentTotals['saldo'] ?? 0,
        ];

        return Inertia::render('FinancialControl/CashRegisterSession/PrintReport', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $sessionTotals,
        ]);
    }

    /**
     * Inicia una nueva sesión de caja (Abre la caja).
     */
    public function store(StoreCashRegisterSessionRequest $request)
    {
        // Validar los datos de las cuentas bancarias además de los de la sesión
        $validated = $request->validated();
        $request->validate([
            'bank_accounts' => 'nullable|array',
            'bank_accounts.*.id' => [
                'required',
                Rule::exists('bank_accounts', 'id')->where(function ($query) {
                    $query->where('subscription_id', Auth::user()->branch->subscription_id);
                }),
            ],
            // 'bank_accounts.*.balance' => 'required|numeric|min:0',
        ]);

        $cashRegister = CashRegister::findOrFail($validated['cash_register_id']);
        $user = User::findOrFail($validated['user_id']);

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['error' => 'Esta caja ya está en uso.']);
        }

        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with(['error' => 'Este usuario ya tiene una sesión activa en otra caja.']);
        }

        DB::transaction(function () use ($request, $validated, $cashRegister, $user) {
            // 1. Crear la sesión de caja
            $session = $cashRegister->sessions()->create([
                'user_id' => $user->id, // Guardamos quién la abrió
                'opening_cash_balance' => $validated['opening_cash_balance'],
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            // 2. Asociar el usuario a la sesión en la tabla pivote
            $session->users()->attach($user->id);

            // 3. Actualizar el estado de la caja
            $cashRegister->update(['in_use' => true]);

            // 4. Actualizar los saldos de las cuentas bancarias
            if ($request->has('bank_accounts')) {
                foreach ($request->input('bank_accounts') as $accountData) {
                    $bankAccount = BankAccount::find($accountData['id']);
                    // La validación anterior ya confirmó que la cuenta pertenece a la suscripción
                    if ($bankAccount) {
                        $bankAccount->update(['balance' => $accountData['balance']]);
                    }
                }
            }
        });

        return redirect()->back()->with('success', 'La caja ha sido abierta con éxito.');
    }

     public function join(Request $request, CashRegisterSession $session)
    {
        $user = Auth::user();

        // Validar que el usuario no esté ya en otra sesión activa
        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with('error', 'Ya tienes una sesión activa.');
        }

        // Añadir el usuario a la sesión
        $session->users()->syncWithoutDetaching([$user->id]);

        // CORRECCIÓN: Se redirige a la página anterior para permanecer en la vista de detalles.
        return redirect()->back()->with('success', 'Te has unido a la sesión de caja.');
    }

    /**
     * Permite a un usuario abandonar una sesión de caja sin cerrarla.
     */
    public function leave(Request $request, CashRegisterSession $session)
    {
        $user = Auth::user();

        // Quitar al usuario de la sesión
        $session->users()->detach($user->id);

        // CORRECCIÓN: Se redirige a la página anterior para que el usuario permanezca en la vista.
        return redirect()->back()->with('success', 'Has salido de la sesión de caja.');
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
}