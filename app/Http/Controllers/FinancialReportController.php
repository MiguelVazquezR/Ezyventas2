<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Exports\FinancialReportExport;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class FinancialReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : Carbon::today()->startOfDay();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : Carbon::today()->endOfDay();

        // Calcular periodos para comparación
        $diffInDays = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($diffInDays + 1);
        $previousEndDate = $startDate->copy()->subDay()->endOfDay();

        // 1. KPIs (Servicio de Reporte)
        // Usamos cache corto (5 min) para KPIs si el rango es grande, opcional.
        $reportService = new FinancialReportService($branchId, $startDate, $endDate);
        $reportData = $reportService->generateReportData();

        // 2. Cálculos de Variación (Ganancia Neta)
        $netProfitCurrent = $reportData['kpis']['sales']['current'] - $reportData['kpis']['expenses']['current'];
        $netProfitPrevious = $reportData['kpis']['sales']['previous'] - $reportData['kpis']['expenses']['previous'];
        
        $reportData['kpis']['netProfit'] = [
            'current' => $netProfitCurrent,
            'previous' => $netProfitPrevious,
            'monetary_change' => $netProfitCurrent - $netProfitPrevious,
            'percentage_change' => $netProfitPrevious != 0 
                ? round((($netProfitCurrent - $netProfitPrevious) / $netProfitPrevious) * 100, 2) 
                : ($netProfitCurrent != 0 ? 100 : 0),
        ];

        // 3. Ticket Promedio (Optimizado con índices)
        // Usamos count() directo que es rápido gracias a los índices creados anteriormente
        $salesCountCurrent = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', TransactionStatus::CANCELLED)
            ->count();

        $averageTicketCurrent = $salesCountCurrent > 0 ? $reportData['kpis']['sales']['current'] / $salesCountCurrent : 0;

        $salesCountPrevious = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->where('status', '!=', TransactionStatus::CANCELLED)
            ->count();

        $averageTicketPrevious = $salesCountPrevious > 0 ? $reportData['kpis']['sales']['previous'] / $salesCountPrevious : 0;

        $reportData['kpis']['averageTicket'] = [
            'current' => $averageTicketCurrent,
            'previous' => $averageTicketPrevious,
            'monetary_change' => $averageTicketCurrent - $averageTicketPrevious,
            'percentage_change' => $averageTicketPrevious != 0 
                ? round((($averageTicketCurrent - $averageTicketPrevious) / $averageTicketPrevious) * 100, 2) 
                : ($averageTicketCurrent != 0 ? 100 : 0),
        ];


        // --- OPTIMIZACIÓN CRÍTICA: DATOS DETALLADOS ---
        // Limitamos a 500-1000 registros para la vista web y seleccionamos solo columnas necesarias.
        // Esto reduce el tamaño del JSON de MBs a KBs.
        $limitWeb = 1000;

        // Gastos Detallados
        $reportData['detailedExpenses'] = Expense::where('branch_id', $branchId)
            ->where('status', ExpenseStatus::PAID->value)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select('id', 'branch_id', 'expense_date', 'expense_category_id', 'description', 'amount', 'payment_method', 'bank_account_id', 'folio')
            ->with(['category:id,name', 'bankAccount:id,account_name,bank_name'])
            ->orderBy('expense_date', 'desc')
            ->limit($limitWeb)
            ->get();

        // Ventas Detalladas
        $reportData['detailedTransactions'] = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->select('id', 'branch_id', 'customer_id', 'created_at', 'folio', 'channel', 'status', 'subtotal', 'total_discount', 'total_tax')
            // Calculamos el total al vuelo para la vista si no existe columna 'total'
            ->selectRaw('(subtotal - total_discount + total_tax) as total') 
            ->with(['customer:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limitWeb)
            ->get();

        // Pagos Detallados
        $reportData['detailedPayments'] = Payment::query()
            ->join('transactions', 'payments.transaction_id', '=', 'transactions.id') // Join es más rápido que whereHas para filtros
            ->where('transactions.branch_id', $branchId)
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->where('payments.payment_method', '!=', PaymentMethod::BALANCE->value)
            ->where('payments.status', PaymentStatus::COMPLETED->value)
            ->select(
                'payments.id', 
                'payments.payment_date', 
                'payments.payment_method', 
                'payments.amount', 
                'payments.transaction_id', 
                'payments.bank_account_id'
            )
            ->with([
                'transaction:id,folio,customer_id', 
                'transaction.customer:id,name', 
                'bankAccount:id,account_name,bank_name'
            ])
            ->orderBy('payments.payment_date', 'desc')
            ->limit($limitWeb)
            ->get();

        // --- Fin Datos Detallados ---

        $subscriptionId = $user->branch->subscription_id;

        // Cuentas Bancarias (Optimizada selección)
        $reportData['bankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)
            ->whereHas('branches', fn($q) => $q->where('branch_id', $branchId))
            ->get();

        $reportData['allBankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)->get();

        return Inertia::render('FinancialControl/Index', $reportData);
    }

    public function export(Request $request)
    {
        // La exportación usa su propia lógica de chunking/query, así que no necesita límites
        $validated = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
        ]);

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        $branchId = Auth::user()->branch_id;

        $fileName = 'Reporte Financiero ' . $startDate->format('d-m-Y') . ' al ' . $endDate->format('d-m-Y') . '.xlsx';

        return Excel::download(new FinancialReportExport($branchId, $startDate, $endDate), $fileName);
    }
}