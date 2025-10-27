<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\SessionClosed;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
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
            'payments.bankAccount',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        $paymentTotals = $cashRegisterSession->payments()
            ->where('status', PaymentStatus::COMPLETED)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $bankAccountSummary = $this->calculateBankAccountSummary($cashRegisterSession, $user, $isOwner);

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

        $bankAccountSummary = $this->calculateBankAccountSummary($cashRegisterSession, $user, $isOwner);

        return Inertia::render('FinancialControl/CashRegisterSession/PrintReport', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $paymentTotals,
            'bankAccountSummary' => $bankAccountSummary,
        ]);
    }

    /**
     * --- NUEVO MÉTODO CENTRALIZADO PARA CALCULAR EL RESUMEN BANCARIO ---
     * Calcula los saldos iniciales y finales de las cuentas bancarias para una sesión,
     * considerando tanto los ingresos por pagos como los egresos por gastos.
     */
    private function calculateBankAccountSummary(CashRegisterSession $session, $user, bool $isOwner): array
    {
        $summary = [];
        if (empty($session->opening_bank_balances)) {
            return $summary;
        }

        $openingBalances = collect($session->opening_bank_balances);
        $accountIdsInSession = $openingBalances->pluck('id');

        // 1. Obtener INGRESOS a cuentas bancarias durante esta sesión
        $paymentsToAccounts = $session->payments()
            ->whereIn('payment_method', [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value])
            ->where('status', PaymentStatus::COMPLETED->value)
            ->whereIn('bank_account_id', $accountIdsInSession)
            ->select('bank_account_id', DB::raw('SUM(amount) as total_received'))
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        // 2. CORRECCIÓN: Obtener GASTOS desde cuentas bancarias durante esta sesión
        $expensesFromAccounts = Expense::where('status', ExpenseStatus::PAID->value)
            ->whereIn('payment_method', [PaymentMethod::CARD->value, PaymentMethod::TRANSFER->value])
            ->whereIn('bank_account_id', $accountIdsInSession)
            // Se usa el rango de fechas de la sesión para encontrar los gastos correspondientes
            ->whereBetween('expense_date', [$session->opened_at?->toDateString(), $session->closed_at?->toDateString()])
            ->select('bank_account_id', DB::raw('SUM(amount) as total_spent'))
            ->groupBy('bank_account_id')
            ->get()
            ->keyBy('bank_account_id');

        // 3. Filtrar por permisos del usuario que está viendo el reporte
        $allowedAccountIds = $isOwner ? $accountIdsInSession : $user->bankAccounts()->pluck('id');

        // 4. Calcular el resumen final
        foreach ($openingBalances as $openingData) {
            if ($allowedAccountIds->contains($openingData['id'])) {
                $received = $paymentsToAccounts->get($openingData['id'])?->total_received ?? 0;
                // Se obtiene el total de gastos para la cuenta
                $spent = $expensesFromAccounts->get($openingData['id'])?->total_spent ?? 0;
                $initialBalance = (float) $openingData['balance'];

                // CORRECCIÓN: El saldo final es el inicial + ingresos - gastos
                $finalBalance = $initialBalance + $received - $spent;

                $summary[] = [
                    'id' => $openingData['id'],
                    'account_name' => $openingData['account_name'],
                    'bank_name' => $openingData['bank_name'],
                    'initial_balance' => $initialBalance,
                    'final_balance' => $finalBalance,
                ];
            }
        }
        return $summary;
    }

    public function store(StoreCashRegisterSessionRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $cashRegister = CashRegister::findOrFail($validated['cash_register_id']);

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['warning' => 'Parece que otro usuario abrió caja antes que tu, puedes unirte a la sesión.']);
        }
        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with(['error' => 'Este usuario ya tiene una sesión activa en otra caja.']);
        }

        DB::transaction(function () use ($request, $validated, $cashRegister, $user) {
            $allBranchAccounts = BankAccount::whereHas('branches', function ($query) use ($cashRegister) {
                $query->where('branch_id', $cashRegister->branch_id);
            })->get();

            $openingBankBalances = $allBranchAccounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'bank_name' => $account->bank_name,
                    'balance' => (float) $account->balance,
                ];
            });

            if ($request->has('bank_accounts')) {
                foreach ($request->input('bank_accounts') as $accountData) {
                    $bankAccount = BankAccount::find($accountData['id']);
                    if ($bankAccount) {
                        $bankAccount->update(['balance' => $accountData['balance']]);
                    }
                }
            }

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

            // --- INICIO DE LA LÓGICA DE BROADCAST ---

            // Guardamos las variables ANTES de que termine la transacción
            $closingUser = Auth::user();
            $session = $cashRegisterSession;

            // Usamos DB::afterCommit para asegurar que el evento solo se envíe
            // si la transacción de la base de datos fue exitosa.
            DB::afterCommit(function () use ($session, $closingUser) {
                // Usamos toOthers() para no enviar el evento al usuario
                // que acaba de cerrar la caja (él ya lo sabe).
                Log::info('Broadcasting SessionClosed event for session ID: ' . $session->id);
                broadcast(new SessionClosed($session, $closingUser))->toOthers();
            });
            // --- FIN DE LA LÓGICA DE BROADCAST ---

        });

        return redirect()->back()->with('success', 'Corte de caja realizado con éxito.');
    }

    /**
     * Inicia o se une a una nueva sesión para una caja registradora específica.
     * Pensado para ser usado después de un cierre forzado.
     */
    public function rejoinOrStart(Request $request)
    {
        $request->validate([
            'cash_register_id' => 'required|integer|exists:cash_registers,id',
            'original_opener_id' => 'required|integer|exists:users,id',
        ]);

        $user = Auth::user();
        $cashRegisterId = $request->input('cash_register_id');
        $originalOpenerId = $request->input('original_opener_id');

        // Validar que el usuario no esté ya en otra sesión
        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with('error', 'Ya tienes una sesión activa.');
        }

        $cashRegister = CashRegister::findOrFail($cashRegisterId);

        // 1. Buscar si ya existe una sesión abierta para esta caja
        // (quizás otro usuario ya la creó)
        $existingSession = $cashRegister->sessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->first();

        if ($existingSession) {
            // Si ya existe, simplemente unimos al usuario
            $existingSession->users()->syncWithoutDetaching([$user->id]);
            return redirect()->back()->with('success', 'Te has unido a la nueva sesión.');
        }

        // 2. Si no existe, crear una nueva
        // Usamos el abridor original como el "dueño" de la sesión
        $opener = User::findOrFail($originalOpenerId);

        $allBranchAccounts = BankAccount::whereHas('branches', function ($query) use ($cashRegister) {
            $query->where('branch_id', $cashRegister->branch_id);
        })->get();

        $openingBankBalances = $allBranchAccounts->map(function ($account) {
            return [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'bank_name' => $account->bank_name,
                'balance' => (float) $account->balance, // Saldo actual
            ];
        });

        $session = DB::transaction(function () use ($cashRegister, $opener, $user, $openingBankBalances) {
            $newSession = $cashRegister->sessions()->create([
                'user_id' => $opener->id,
                'opening_cash_balance' => 0.00, // Se asume 0 para una reapertura rápida
                'opening_bank_balances' => $openingBankBalances,
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            // Unimos al abridor original Y al usuario actual
            $newSession->users()->attach(array_unique([$opener->id, $user->id]));
            $cashRegister->update(['in_use' => true]);

            return $newSession;
        });

        return redirect()->back()->with('success', 'Se ha creado una nueva sesión y te has unido.');
    }
}
