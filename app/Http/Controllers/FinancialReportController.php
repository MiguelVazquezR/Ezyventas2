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
        // NOTA: Si los totales generales (Ventas Totales, Ganancia Neta) siguen incorrectos,
        // deberás aplicar este mismo filtro (excluir TransactionStatus::CHANGED) dentro de 'FinancialReportService'.
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

        // --- NUEVO CÁLCULO: Margen de Utilidad (%) ---
        // Fórmula: (Ganancia Neta / Ventas Totales) * 100
        $salesCurrent = $reportData['kpis']['sales']['current'];
        $salesPrevious = $reportData['kpis']['sales']['previous'];

        $utilityMarginCurrent = $salesCurrent != 0 
            ? round(($netProfitCurrent / $salesCurrent) * 100, 2) 
            : 0;

        $utilityMarginPrevious = $salesPrevious != 0 
            ? round(($netProfitPrevious / $salesPrevious) * 100, 2) 
            : 0;

        $reportData['kpis']['utilityMargin'] = [
            'current' => $utilityMarginCurrent,
            'previous' => $utilityMarginPrevious,
            'change' => round($utilityMarginCurrent - $utilityMarginPrevious, 2) // Cambio en puntos porcentuales
        ];
        // ----------------------------------------------


        // 3. Ticket Promedio (Optimizado con índices)
        // CORRECCIÓN: Ahora excluimos tanto CANCELLED como CHANGED para que el conteo sea real.
        $salesCountCurrent = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->count();

        $averageTicketCurrent = $salesCountCurrent > 0 ? $reportData['kpis']['sales']['current'] / $salesCountCurrent : 0;

        $salesCountPrevious = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
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
        // CORRECCIÓN: Filtramos las ventas cambiadas para que no aparezcan en la lista detallada
        $reportData['detailedTransactions'] = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::CHANGED])
            ->select('id', 'branch_id', 'customer_id', 'created_at', 'folio', 'channel', 'status', 'subtotal', 'total_discount', 'total_tax')
            // Calculamos el total al vuelo para la vista si no existe columna 'total'
            ->selectRaw('(subtotal - total_discount + total_tax) as total') 
            ->with(['customer:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limitWeb)
            ->get();

        // Pagos Detallados
        $reportData['detailedPayments'] = Payment::query()
            ->join('transactions', 'payments.transaction_id', '=', 'transactions.id') 
            ->where('transactions.branch_id', $branchId)
            ->whereBetween('payments.payment_date', [$startDate, $endDate])
            ->where('payments.payment_method', '!=', PaymentMethod::BALANCE->value)
            ->where('payments.status', PaymentStatus::COMPLETED->value)
            // Opcional: Si también quieres ocultar pagos de ventas que luego fueron cambiadas, descomenta la siguiente línea:
            // ->whereNotIn('transactions.status', [TransactionStatus::CANCELLED->value, TransactionStatus::CHANGED->value])
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

        // Cuentas Bancarias
        $reportData['bankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)
            ->whereHas('branches', fn($q) => $q->where('branch_id', $branchId))
            ->get();

        $reportData['allBankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)->get();

        return Inertia::render('FinancialControl/Index', $reportData);
    }

    public function export(Request $request)
    {
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