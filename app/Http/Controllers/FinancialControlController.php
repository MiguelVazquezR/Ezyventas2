<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\Expense;
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

        // Obtener KPIs para el rango de fechas seleccionado
        $totalIncome = Transaction::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->where('status', 'completado')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum(DB::raw('subtotal - total_discount'));
            
        $totalExpenses = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // Datos para la gráfica, adaptados al rango de fechas
        $incomeByDay = Transaction::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->where('status', 'completado')->whereBetween('created_at', [$startDate, $endDate])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(subtotal - total_discount) as total'))
            ->groupBy('date')->pluck('total', 'date');

        $expensesByDay = Expense::whereHas('branch.subscription', fn($q) => $q->where('id', $subscriptionId))
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select(DB::raw('DATE(expense_date) as date'), DB::raw('sum(amount) as total'))
            ->groupBy('date')->pluck('total', 'date');
        
        $period = CarbonPeriod::create($startDate, $endDate);
        $chartData = ['labels' => [], 'income' => [], 'expenses' => []];

        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m-d');
            $chartData['labels'][] = $date->format('d M');
            $chartData['income'][] = $incomeByDay[$formattedDate] ?? 0;
            $chartData['expenses'][] = $expensesByDay[$formattedDate] ?? 0;
        }

        return Inertia::render('FinancialControl/Index', [
            'kpis' => [
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'netProfit' => $totalIncome - $totalExpenses,
            ],
            'chartData' => $chartData,
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