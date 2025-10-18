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

    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $query = CashRegisterSession::query()
            ->join('users', 'cash_register_sessions.user_id', '=', 'users.id')
            ->join('cash_registers', 'cash_register_sessions.cash_register_id', '=', 'cash_registers.id')
            ->where('cash_register_sessions.status', CashRegisterSessionStatus::CLOSED)
            ->whereHas('cashRegister.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            })
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

        $sortColumn = match ($sortField) {
            'opener.name' => 'users.name',
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

    public function show(CashRegisterSession $cashRegisterSession): Response
    {
        $user = Auth::user();
        $isOwner = !$user->roles()->exists();

        $cashRegisterSession->load([
            'opener:id,name',
            'users:id,name',
            'cashRegister:id,name',
            'payments.bankAccount', // Cargar la relación para obtener el saldo actual
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // --- CÁLCULO DE SALDOS DE CUENTAS BANCARIAS ---
        $bankAccountSummary = [];
        if (!empty($cashRegisterSession->opening_bank_balances)) {
            $openingBalances = collect($cashRegisterSession->opening_bank_balances);
            $accountIdsInSession = $openingBalances->pluck('id');

            // Pagos hechos a cuentas bancarias durante esta sesión
            $paymentsToAccounts = $cashRegisterSession->payments()
                ->whereIn('payment_method', ['tarjeta', 'transferencia'])
                ->where('status', 'completado')
                ->whereIn('bank_account_id', $accountIdsInSession)
                ->select('bank_account_id', DB::raw('SUM(amount) as total_received'))
                ->groupBy('bank_account_id')
                ->get()
                ->keyBy('bank_account_id');

            // Filtrar por permisos del usuario que está viendo el reporte
            $allowedAccountIds = $isOwner ? $accountIdsInSession : $user->bankAccounts()->pluck('id');

            foreach ($openingBalances as $openingData) {
                if ($allowedAccountIds->contains($openingData['id'])) {
                    $received = $paymentsToAccounts->get($openingData['id'])?->total_received ?? 0;
                    $initialBalance = (float) $openingData['balance'];
                    
                    // El saldo final es el inicial más lo que se recibió en esta sesión
                    $finalBalance = $initialBalance + $received;

                    $bankAccountSummary[] = [
                        'id' => $openingData['id'],
                        'account_name' => $openingData['account_name'],
                        'bank_name' => $openingData['bank_name'],
                        'initial_balance' => $initialBalance,
                        'final_balance' => $finalBalance,
                    ];
                }
            }
        }

        return Inertia::render('FinancialControl/CashRegisterSession/Show', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $paymentTotals,
            'bankAccountSummary' => $bankAccountSummary,
        ]);
    }


    public function print(CashRegisterSession $cashRegisterSession): Response
    {
        $user = Auth::user();
        $isOwner = !$user->roles()->exists();

        // CORRECCIÓN: Se carga 'opener' en lugar de 'user' y se añaden otras relaciones para un reporte completo.
        $cashRegisterSession->load([
            'opener:id,name',
            'users:id,name',
            'cashRegister.branch.subscription',
            'payments.bankAccount',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', 'completado')
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // --- CÁLCULO DE SALDOS DE CUENTAS BANCARIAS (AÑADIDO) ---
        $bankAccountSummary = [];
        if (!empty($cashRegisterSession->opening_bank_balances)) {
            $openingBalances = collect($cashRegisterSession->opening_bank_balances);
            $accountIdsInSession = $openingBalances->pluck('id');

            $paymentsToAccounts = $cashRegisterSession->payments()
                ->whereIn('payment_method', ['tarjeta', 'transferencia'])
                ->where('status', 'completado')
                ->whereIn('bank_account_id', $accountIdsInSession)
                ->select('bank_account_id', DB::raw('SUM(amount) as total_received'))
                ->groupBy('bank_account_id')
                ->get()
                ->keyBy('bank_account_id');

            $allowedAccountIds = $isOwner ? $accountIdsInSession : $user->bankAccounts()->pluck('id');

            foreach ($openingBalances as $openingData) {
                if ($allowedAccountIds->contains($openingData['id'])) {
                    $received = $paymentsToAccounts->get($openingData['id'])?->total_received ?? 0;
                    $initialBalance = (float) $openingData['balance'];
                    $finalBalance = $initialBalance + $received;

                    $bankAccountSummary[] = [
                        'id' => $openingData['id'],
                        'account_name' => $openingData['account_name'],
                        'bank_name' => $openingData['bank_name'],
                        'initial_balance' => $initialBalance,
                        'final_balance' => $finalBalance,
                    ];
                }
            }
        }

        return Inertia::render('FinancialControl/CashRegisterSession/PrintReport', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $paymentTotals,
            'bankAccountSummary' => $bankAccountSummary, // Se pasa a la vista de impresión
        ]);
    }

    public function store(StoreCashRegisterSessionRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $cashRegister = CashRegister::findOrFail($validated['cash_register_id']);

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['error' => 'Esta caja ya está en uso.']);
        }
        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with(['error' => 'Este usuario ya tiene una sesión activa en otra caja.']);
        }

        DB::transaction(function () use ($request, $validated, $cashRegister, $user) {
            
            // --- LÓGICA MEJORADA ---
            // 1. Obtener TODAS las cuentas bancarias de la sucursal.
            $allBranchAccounts = BankAccount::whereHas('branches', function ($query) use ($cashRegister) {
                $query->where('branch_id', $cashRegister->branch_id);
            })->get();

            // 2. Crear la "foto" del estado inicial de TODAS las cuentas.
            $openingBankBalances = $allBranchAccounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'bank_name' => $account->bank_name,
                    'balance' => (float) $account->balance,
                ];
            });

            // 3. (Opcional) Si el usuario ajustó saldos en el modal, actualizarlos en la BD.
            if ($request->has('bank_accounts')) {
                foreach ($request->input('bank_accounts') as $accountData) {
                    $bankAccount = BankAccount::find($accountData['id']);
                    if ($bankAccount) {
                        $bankAccount->update(['balance' => $accountData['balance']]);
                    }
                }
            }

            // 4. Crear la sesión de caja con la "foto" completa.
            $session = $cashRegister->sessions()->create([
                'user_id' => $user->id,
                'opening_cash_balance' => $validated['opening_cash_balance'],
                'opening_bank_balances' => $openingBankBalances,
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            $session->users()->attach($user->id);
            $cashRegister->update(['in_use' => true]);
        });

        return redirect()->back()->with('success', 'La caja ha sido abierta con éxito.');
    }

    public function join(Request $request, CashRegisterSession $session)
    {
        $user = Auth::user();

        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with('error', 'Ya tienes una sesión activa.');
        }

        $session->users()->syncWithoutDetaching([$user->id]);
        return redirect()->back()->with('success', 'Te has unido a la sesión de caja.');
    }

    public function leave(Request $request, CashRegisterSession $session)
    {
        $user = Auth::user();
        $session->users()->detach($user->id);
        return redirect()->back()->with('success', 'Has salido de la sesión de caja.');
    }

    public function update(UpdateCashRegisterSessionRequest $request, CashRegisterSession $cashRegisterSession)
    {
        DB::transaction(function () use ($request, $cashRegisterSession) {
            $validated = $request->validated();
            
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