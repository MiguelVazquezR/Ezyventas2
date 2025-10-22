<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
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
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class FinancialReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $startDate = $request->input('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : Carbon::today()->startOfDay();
        $endDate = $request->input('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : Carbon::today()->endOfDay();

        $reportService = new FinancialReportService($branchId, $startDate, $endDate);

        $reportData = $reportService->generateReportData();

        // --- Calcular Ganancia Neta ---
        $netProfitCurrent = $reportData['kpis']['sales']['current'] - $reportData['kpis']['expenses']['current'];
        $netProfitPrevious = $reportData['kpis']['sales']['previous'] - $reportData['kpis']['expenses']['previous'];
        $netProfitMonetaryChange = $netProfitCurrent - $netProfitPrevious;
        $netProfitPercentageChange = $netProfitPrevious != 0
            ? ($netProfitMonetaryChange / $netProfitPrevious) * 100
            : ($netProfitCurrent > 0 ? 100 : 0);

        $reportData['kpis']['netProfit'] = [
            'current' => $netProfitCurrent,
            'previous' => $netProfitPrevious, // Nota: El cálculo del periodo anterior aquí asume que los datos base ya están ajustados.
            'monetary_change' => $netProfitMonetaryChange,
            'percentage_change' => round($netProfitPercentageChange, 2),
        ];

        // --- Calcular Saldos Pendientes (Nuevo KPI) ---
        $pendingBalanceCurrent = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', TransactionStatus::PENDING)
            ->sum(DB::raw('subtotal - total_discount + total_tax')); // Suma del total calculado para pendientes

        // (Opcional: Calcular periodo anterior para comparación)
        // Necesitarías replicar la lógica de FinancialReportService para el rango anterior
        $pendingBalancePrevious = 0; // Simplificado por ahora
        $pendingBalanceMonetaryChange = $pendingBalanceCurrent - $pendingBalancePrevious;
        $pendingBalancePercentageChange = $pendingBalancePrevious != 0
            ? ($pendingBalanceMonetaryChange / $pendingBalancePrevious) * 100
            : ($pendingBalanceCurrent > 0 ? 100 : 0);

        $reportData['kpis']['pendingBalance'] = [
            'current' => $pendingBalanceCurrent,
            'previous' => $pendingBalancePrevious,
            'monetary_change' => $pendingBalanceMonetaryChange,
            'percentage_change' => round($pendingBalancePercentageChange, 2),
        ];
        // --- Fin Saldos Pendientes ---


        // --- Obtener Datos Detallados para Modales ---
        // Gastos (Ya estaba)
        $detailedExpenses = Expense::where('branch_id', $branchId)
            ->where('status', ExpenseStatus::PAID)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with(['category:id,name', 'bankAccount:id,account_name,bank_name'])
            ->orderBy('expense_date', 'desc')
            ->get();
        $reportData['detailedExpensesByCategory'] = $detailedExpenses->groupBy('category.name');

        // Ventas (Transacciones)
        $reportData['detailedTransactions'] = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['customer:id,name']) // Cargar cliente para mostrar en modal
            ->orderBy('created_at', 'desc')
            ->get();

        // Pagos
        $reportData['detailedPayments'] = Payment::whereHas('transaction', function ($query) use ($branchId, $startDate, $endDate) {
            $query->where('branch_id', $branchId)
                ->whereBetween('created_at', [$startDate, $endDate]);
        })
            ->where('status', PaymentStatus::COMPLETED) // Solo pagos completados
            ->with(['transaction:id,folio', 'transaction.customer:id,name', 'bankAccount:id,account_name,bank_name']) // Cargar relaciones
            ->orderBy('payment_date', 'desc')
            ->get();
        // --- Fin Datos Detallados ---


        $subscriptionId = $user->branch->subscription_id;
        $allBankAccounts = BankAccount::where('subscription_id', $subscriptionId)->get();
        $reportData['allBankAccounts'] = $allBankAccounts;


        return Inertia::render('FinancialControl/Index', $reportData);
    }

    /**
     * Genera y descarga el reporte financiero en formato Excel.
     */
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
