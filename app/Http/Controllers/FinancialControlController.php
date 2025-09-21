<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class FinancialControlController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : Carbon::today();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : Carbon::today();
        $endDate->endOfDay();

        // 1. KPIs principales
        $totalIncome = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->sum('amount');

        $totalExpenses = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // 2. Datos para la gráfica de líneas
        $incomeByDay = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->select(DB::raw('DATE(payment_date) as date'), DB::raw('sum(amount) as total'))
            ->groupBy('date')->pluck('total', 'date');

        $expensesByDay = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select(DB::raw('DATE(expense_date) as date'), DB::raw('sum(amount) as total'))
            ->groupBy('date')->pluck('total', 'date');

        $period = CarbonPeriod::create($startDate, $endDate);
        $chartData = ['labels' => [], 'income' => [], 'expenses' => []];

        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m-d');
            $chartData['labels'][] = $period->count() > 31 ? $date->format('M Y') : $date->format('d M');
            $chartData['income'][] = $incomeByDay[$formattedDate] ?? 0;
            $chartData['expenses'][] = $expensesByDay[$formattedDate] ?? 0;
        }

        // 3. Desglose de ingresos por método de pago (SOLUCIÓN)
        $incomeByMethod = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->get()
            // Transformar la colección para usar el valor del Enum como clave
            ->keyBy(fn($payment) => $payment->payment_method->value)
            ->map(fn($payment) => $payment->total);

        $formattedIncomeByMethod = [
            'labels' => $incomeByMethod->keys()->map(fn($method) => ucfirst($method))->toArray(),
            'data' => $incomeByMethod->values()->toArray(),
        ];

        $cashRegisters = CashRegister::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->with(['sessions' => function ($query) {
                $query->where('status', 'abierta')->with(['user:id,name', 'cashMovements', 'transactions.payments']);
            }])
            ->get();

        // Calcular el balance actual para las cajas abiertas
        $cashRegisters->each(function ($register) {
            $activeSession = $register->sessions->first();
            if ($activeSession) {
                $cashSales = $activeSession->transactions
                    ->flatMap->payments
                    ->where('payment_method', PaymentMethod::CASH)
                    ->where('status', 'completado')
                    ->sum('amount');

                $inflows = $activeSession->cashMovements->where('type', 'ingreso')->sum('amount');
                $outflows = $activeSession->cashMovements->where('type', 'egreso')->sum('amount');

                $register->current_balance = $activeSession->opening_cash_balance + $cashSales + $inflows - $outflows;
                $register->active_session_user = $activeSession->user->name; // Añadir el nombre del usuario
            }
        });

        return Inertia::render('FinancialControl/Index', [
            'kpis' => [
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'netProfit' => $totalIncome - $totalExpenses,
            ],
            'chartData' => $chartData,
            'incomeByMethod' => $formattedIncomeByMethod,
            'cashRegisters' => $cashRegisters, // Enviar los datos enriquecidos a la vista
            'bankAccounts' => BankAccount::where('subscription_id', $subscriptionId)->get(),
            'recentSessions' => CashRegisterSession::whereHas('cashRegister.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
                ->where('status', 'cerrada')->latest()->take(5)->get(),
            'filters' => [
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
            ]
        ]);
    }
}
