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

        // Punto 1 y 2: Manejar el rango de fechas, con el día de hoy como valor por defecto
        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date')) : Carbon::today();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date')) : Carbon::today();
        
        $endDate->endOfDay(); // Asegurarse de que incluya todo el día final

        // Obtener KPIs para el rango de fechas seleccionado (basados en Pagos)
        $totalIncome = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->sum('amount');
            
        $totalExpenses = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // Datos para la gráfica, adaptados al rango de fechas
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

        // Desglose de ingresos por método de pago
        $incomeByMethod = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->get()->pluck('total', 'payment_method');
            
        $formattedIncomeByMethod = [
            'labels' => $incomeByMethod->keys()->map(fn($method) => ucfirst($method))->toArray(),
            'data' => $incomeByMethod->values()->toArray(),
        ];

        return Inertia::render('FinancialControl/Index', [
            'kpis' => [
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'netProfit' => $totalIncome - $totalExpenses,
            ],
            'chartData' => $chartData,
            'incomeByMethod' => $formattedIncomeByMethod,
            'cashRegisters' => CashRegister::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))->with('sessions')->get(),
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