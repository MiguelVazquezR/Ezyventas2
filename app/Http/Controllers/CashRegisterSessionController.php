<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Events\SessionClosed;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest;
use App\Http\Requests\CashRegisterSessions\UpdateClosingCashBalanceRequest;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
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
            ->select('cash_register_sessions.*')
            ->withSum([
                'cashPayments as total_cash_sales',
                'inflowMovements as total_inflows',
                'outflowMovements as total_outflows',
            ], 'amount');

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
            'payments.transaction.customer:id,name',
            'payments.transaction.user:id,name',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        return Inertia::render('FinancialControl/CashRegisterSession/Show', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $cashRegisterSession->getCompletedPaymentTotals(),
            'bankAccountSummary' => $cashRegisterSession->calculateBankAccountSummary($user, $isOwner),
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
            'payments.transaction.customer:id,name',
            'payments.transaction.user:id,name',
            'cashMovements.user:id,name',
            'transactions.user:id,name',
            'transactions.customer:id,name'
        ]);

        return Inertia::render('FinancialControl/CashRegisterSession/PrintReport', [
            'session' => $cashRegisterSession,
            'sessionTotals' => $cashRegisterSession->getCompletedPaymentTotals(),
            'bankAccountSummary' => $cashRegisterSession->calculateBankAccountSummary($user, $isOwner),
        ]);
    }

    public function store(StoreCashRegisterSessionRequest $request)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $cashRegister = CashRegister::findOrFail($validated['cash_register_id']);

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['warning' => 'Parece que otro usuario abrió caja antes que tu, puedes unirte a la sesión.']);
        }

        DB::transaction(function () use ($request, $validated, $cashRegister, $user) {
            // 1. Aplicar PRIMERO los saldos confirmados por el cajero en el modal.
            //    Así el snapshot (opening_bank_balances) coincide con lo declarado
            //    al abrir la caja y no con el valor anterior a la corrección.
            if ($request->has('bank_accounts')) {
                foreach ($request->input('bank_accounts') as $accountData) {
                    $bankAccount = BankAccount::find($accountData['id'] ?? null);
                    if ($bankAccount) {
                        $bankAccount->update(['balance' => (float) $accountData['balance']]);
                    }
                }
            }

            // 2. Construir el snapshot de saldos DESPUÉS de aplicar las correcciones.
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

            // Delegamos el proceso matemático al Modelo
            $cashRegisterSession->closeSession(
                (float) $validated['closing_cash_balance'], 
                $validated['notes'] ?? null
            );

            $closingUser = Auth::user();

            DB::afterCommit(function () use ($cashRegisterSession, $closingUser) {
                Log::info('Broadcasting SessionClosed event for session ID: ' . $cashRegisterSession->id);
                broadcast(new SessionClosed($cashRegisterSession, $closingUser))->toOthers();
            });
        });

        return redirect()->back()->with('success', 'Corte de caja realizado con éxito.');
    }

    public function updateClosingCashBalance(UpdateClosingCashBalanceRequest $request, CashRegisterSession $cashRegisterSession)
    {
        $cashRegisterSession->updateClosingCashBalance(
            (float) $request->validated('closing_cash_balance')
        );

        return redirect()->back()->with('success', 'El monto de contado físico ha sido actualizado.');
    }

    public function rejoinOrStart(Request $request)
    {
        $request->validate([
            'cash_register_id' => 'required|integer|exists:cash_registers,id',
            'original_opener_id' => 'required|integer|exists:users,id',
        ]);

        $user = Auth::user();
        $cashRegisterId = $request->input('cash_register_id');
        $originalOpenerId = $request->input('original_opener_id');

        if ($user->cashRegisterSessions()->where('status', 'abierta')->exists()) {
            return redirect()->back()->with('error', 'Ya tienes una sesión activa.');
        }

        $cashRegister = CashRegister::findOrFail($cashRegisterId);
        $existingSession = $cashRegister->sessions()
            ->where('status', CashRegisterSessionStatus::OPEN)
            ->first();

        if ($existingSession) {
            $existingSession->users()->syncWithoutDetaching([$user->id]);
            return redirect()->back()->with('success', 'Te has unido a la nueva sesión.');
        }

        $opener = User::findOrFail($originalOpenerId);

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

        $session = DB::transaction(function () use ($cashRegister, $opener, $user, $openingBankBalances) {
            $newSession = $cashRegister->sessions()->create([
                'user_id' => $opener->id,
                'opening_cash_balance' => 0.00,
                'opening_bank_balances' => $openingBankBalances,
                'status' => CashRegisterSessionStatus::OPEN,
                'opened_at' => now(),
            ]);

            $newSession->users()->attach(array_unique([$opener->id, $user->id]));
            $cashRegister->update(['in_use' => true]);

            return $newSession;
        });

        return redirect()->back()->with('success', 'Se ha creado una nueva sesión y te has unido.');
    }
}