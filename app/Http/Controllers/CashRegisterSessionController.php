<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterSessionStatus;
use App\Http\Requests\StoreCashRegisterSessionRequest;
use App\Http\Requests\UpdateCashRegisterSessionRequest;
use App\Http\Requests\CashRegisterSessions\UpdateClosingCashBalanceRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\User;
use App\Services\CashRegisters\CashRegisterSessionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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

    public function store(StoreCashRegisterSessionRequest $request, CashRegisterSessionLifecycleService $lifecycle)
    {
        $validated = $request->validated();
        $user = Auth::user();
        $cashRegister = CashRegister::findOrFail($validated['cash_register_id']);

        if ($cashRegister->in_use) {
            return redirect()->back()->with(['warning' => 'Parece que otro usuario abrió caja antes que tu, puedes unirte a la sesión.']);
        }

        $lifecycle->open(
            $cashRegister,
            $user,
            (float) $validated['opening_cash_balance'],
            $request->input('bank_accounts', [])
        );

        return redirect()->back()->with('success', 'La caja ha sido abierta con éxito.');
    }

    public function join(Request $request, CashRegisterSession $session, CashRegisterSessionLifecycleService $lifecycle)
    {
        $lifecycle->join($session, Auth::user());

        return redirect()->back()->with('success', 'Te has unido a la sesión de caja.');
    }

    public function leave(Request $request, CashRegisterSession $session, CashRegisterSessionLifecycleService $lifecycle)
    {
        $lifecycle->leave($session, Auth::user());

        return redirect()->back()->with('success', 'Has salido de la sesión de caja.');
    }

    public function update(
        UpdateCashRegisterSessionRequest $request,
        CashRegisterSession $cashRegisterSession,
        CashRegisterSessionLifecycleService $lifecycle,
    ) {
        $validated = $request->validated();

        $lifecycle->close(
            $cashRegisterSession,
            (float) $validated['closing_cash_balance'],
            $validated['notes'] ?? null,
            Auth::user()
        );

        return redirect()->back()->with('success', 'Corte de caja realizado con éxito.');
    }

    public function updateClosingCashBalance(UpdateClosingCashBalanceRequest $request, CashRegisterSession $cashRegisterSession)
    {
        $cashRegisterSession->updateClosingCashBalance(
            (float) $request->validated('closing_cash_balance')
        );

        return redirect()->back()->with('success', 'El monto de contado físico ha sido actualizado.');
    }

    public function rejoinOrStart(Request $request, CashRegisterSessionLifecycleService $lifecycle)
    {
        $request->validate([
            'cash_register_id' => 'required|integer|exists:cash_registers,id',
            'original_opener_id' => 'required|integer|exists:users,id',
        ]);

        $user = Auth::user();

        if ($user->cashRegisterSessions()->where('status', CashRegisterSessionStatus::OPEN->value)->exists()) {
            return redirect()->back()->with('error', 'Ya tienes una sesión activa.');
        }

        $cashRegister = CashRegister::findOrFail($request->input('cash_register_id'));
        $opener = User::findOrFail($request->input('original_opener_id'));

        $wasAlreadyOpen = (bool) $lifecycle->openSessionOn($cashRegister);
        $lifecycle->rejoinOrStart($cashRegister, $user, $opener);

        return redirect()->back()->with('success', $wasAlreadyOpen
            ? 'Te has unido a la nueva sesión.'
            : 'Se ha creado una nueva sesión y te has unido.');
    }
}
