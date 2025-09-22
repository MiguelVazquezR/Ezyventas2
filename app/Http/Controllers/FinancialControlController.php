<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
use App\Models\Payment;
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
        $isSingleDay = $startDate->isSameDay($endDate);
        
        $endDate->endOfDay();

        // 1. KPIs principales
        $totalIncome = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->sum('amount');
            
        $totalExpenses = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // 2. Lógica de Gráfica Inteligente
        $chartData = ['labels' => [], 'income' => [], 'expenses' => []];

        if ($isSingleDay) { // Vista por Hora (Formato 12-horas AM/PM)
            $groupByFormat = '%l %p'; // Formato SQL para '1 AM', '2 AM', etc.
            $incomeByPoint = $this->getChartData(Payment::class, 'payment_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            $expensesByPoint = $this->getChartData(Expense::class, 'expense_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            
            for ($hour = 0; $hour < 24; $hour++) {
                $carbonHour = Carbon::today()->startOfDay()->addHours($hour);
                $label = $carbonHour->format('g A'); // Formato PHP: '1 AM', '12 PM', etc.
                $chartData['labels'][] = $label;
                $chartData['income'][] = $incomeByPoint[$label] ?? 0;
                $chartData['expenses'][] = $expensesByPoint[$label] ?? 0;
            }
        } elseif ($startDate->diffInDays($endDate) <= 90) { // Vista por Día
            $groupByFormat = '%Y-%m-%d';
            $incomeByPoint = $this->getChartData(Payment::class, 'payment_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            $expensesByPoint = $this->getChartData(Expense::class, 'expense_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            $period = CarbonPeriod::create($startDate, $endDate);
            foreach ($period as $date) {
                $label = $date->format('Y-m-d');
                $chartData['labels'][] = $date->format('d M');
                $chartData['income'][] = $incomeByPoint[$label] ?? 0;
                $chartData['expenses'][] = $expensesByPoint[$label] ?? 0;
            }
        } else { // Vista por Mes
            $groupByFormat = '%Y-%m';
            $incomeByPoint = $this->getChartData(Payment::class, 'payment_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            $expensesByPoint = $this->getChartData(Expense::class, 'expense_date', $startDate, $endDate, $subscriptionId, $groupByFormat);
            $period = CarbonPeriod::create($startDate->startOfMonth(), '1 month', $endDate->endOfMonth());
            foreach ($period as $date) {
                $label = $date->format('Y-m');
                $chartData['labels'][] = $date->isoFormat('MMM YYYY');
                $chartData['income'][] = $incomeByPoint[$label] ?? 0;
                $chartData['expenses'][] = $expensesByPoint[$label] ?? 0;
            }
        }
        
        // 3. Desglose de ingresos por método de pago
        $incomeByMethod = Payment::where('status', 'completado')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->get()->keyBy(fn($payment) => $payment->payment_method->value)->map(fn($payment) => $payment->total);
            
        $formattedIncomeByMethod = [
            'labels' => $incomeByMethod->keys()->map(fn($method) => ucfirst($method))->toArray(),
            'data' => $incomeByMethod->values()->toArray(),
        ];

        // 4. Lógica para Cajas Registradas
        $cashRegisters = CashRegister::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->with(['sessions' => fn ($query) => $query->where('status', 'abierta')->with(['user:id,name', 'cashMovements', 'transactions.payments'])])->get();

        $cashRegisters->each(function ($register) {
            $activeSession = $register->sessions->first();
            if ($activeSession) {
                $cashSales = $activeSession->transactions->flatMap->payments->where('payment_method', PaymentMethod::CASH)->where('status', 'completado')->sum('amount');
                $inflows = $activeSession->cashMovements->where('type', 'ingreso')->sum('amount');
                $outflows = $activeSession->cashMovements->where('type', 'egreso')->sum('amount');
                $register->current_balance = $activeSession->opening_cash_balance + $cashSales + $inflows - $outflows;
                $register->active_session_user = $activeSession->user->name;
            }
        });

        return Inertia::render('FinancialControl/Index', [
            'kpis' => ['totalIncome' => $totalIncome, 'totalExpenses' => $totalExpenses, 'netProfit' => $totalIncome - $totalExpenses],
            'chartData' => $chartData,
            'incomeByMethod' => $formattedIncomeByMethod,
            'cashRegisters' => $cashRegisters,
            'bankAccounts' => BankAccount::where('subscription_id', $subscriptionId)->get(),
            'recentSessions' => CashRegisterSession::whereHas('cashRegister.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
                ->where('status', 'cerrada')->latest()->take(5)->get(),
            'filters' => ['startDate' => $startDate->toDateString(), 'endDate' => $endDate->toDateString()]
        ]);
    }

    private function getChartData($model, $dateColumn, $startDate, $endDate, $subscriptionId, $format)
    {
        $query = $model === Payment::class
            ? $model::whereHas('transaction.branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            : $model::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId));
            
        return $query->whereBetween($dateColumn, [$startDate, $endDate])
            ->select(DB::raw("DATE_FORMAT({$dateColumn}, '{$format}') as point"), DB::raw('sum(amount) as total'))
            ->groupBy('point')->pluck('total', 'point');
    }
}

