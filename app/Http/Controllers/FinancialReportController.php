<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod; // Asegúrate de importar PaymentMethod
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
use Illuminate\Support\Facades\DB; // Asegúrate de importar DB
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

        // --- Calcular periodos para comparación ---
        $diffInDays = $startDate->diffInDays($endDate);
        $previousStartDate = $startDate->copy()->subDays($diffInDays + 1);
        $previousEndDate = $startDate->copy()->subDay()->endOfDay();

        $reportService = new FinancialReportService($branchId, $startDate, $endDate);
        $reportData = $reportService->generateReportData();

        // --- Calcular Ganancia Neta ---
        $netProfitCurrent = $reportData['kpis']['sales']['current'] - $reportData['kpis']['expenses']['current'];
        $netProfitPrevious = $reportData['kpis']['sales']['previous'] - $reportData['kpis']['expenses']['previous'];
        $netProfitMonetaryChange = $netProfitCurrent - $netProfitPrevious;
        $netProfitPercentageChange = $netProfitPrevious != 0
            ? ($netProfitMonetaryChange / $netProfitPrevious) * 100
            : ($netProfitCurrent != 0 ? 100 : 0);

        $reportData['kpis']['netProfit'] = [
            'current' => $netProfitCurrent,
            'previous' => $netProfitPrevious,
            'monetary_change' => $netProfitMonetaryChange,
            'percentage_change' => round($netProfitPercentageChange, 2),
        ];

        // --- Calcular Ticket Promedio (Nuevo KPI) ---
        $salesCountCurrent = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('status', '!=', TransactionStatus::CANCELLED) // Excluir canceladas
            ->count();
        $averageTicketCurrent = $salesCountCurrent > 0 ? $reportData['kpis']['sales']['current'] / $salesCountCurrent : 0;

        $salesCountPrevious = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$previousStartDate, $previousEndDate])
            ->where('status', '!=', TransactionStatus::CANCELLED)
            ->count();
        $averageTicketPrevious = $salesCountPrevious > 0 ? $reportData['kpis']['sales']['previous'] / $salesCountPrevious : 0;

        $averageTicketMonetaryChange = $averageTicketCurrent - $averageTicketPrevious;
        $averageTicketPercentageChange = $averageTicketPrevious != 0
            ? ($averageTicketMonetaryChange / $averageTicketPrevious) * 100
            : ($averageTicketCurrent != 0 ? 100 : 0);

        $reportData['kpis']['averageTicket'] = [
            'current' => $averageTicketCurrent,
            'previous' => $averageTicketPrevious,
            'monetary_change' => $averageTicketMonetaryChange,
            'percentage_change' => round($averageTicketPercentageChange, 2),
        ];
        // --- Fin Ticket Promedio ---


        // --- Obtener Datos Detallados para Modales ---
        // Gastos
        $detailedExpenses = Expense::where('branch_id', $branchId)
            ->where('status', ExpenseStatus::PAID->value) // Usar ->value para Enums
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with(['category:id,name', 'bankAccount:id,account_name,bank_name'])
            ->orderBy('expense_date', 'desc')
            ->get();
        // $reportData['detailedExpensesByCategory'] = $detailedExpenses->groupBy('category.name'); // Ya no se necesita agrupar para el modal eliminado
        $reportData['detailedExpenses'] = $detailedExpenses;

        // Ventas (Transacciones)
        $reportData['detailedTransactions'] = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with(['customer:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Pagos
        $reportData['detailedPayments'] = Payment::whereHas('transaction', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('payment_method', '!=', PaymentMethod::BALANCE->value)
            ->where('status', PaymentStatus::COMPLETED->value) // Usar ->value para Enums
            ->with(['transaction:id,folio', 'transaction.customer:id,name', 'bankAccount:id,account_name,bank_name'])
            ->orderBy('payment_date', 'desc')
            ->get();
        // --- Fin Datos Detallados ---

        $subscriptionId = $user->branch->subscription_id;

        // *** CORRECCIÓN: Filtrar cuentas por sucursal actual ***
        $reportData['bankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)
            ->whereHas('branches', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId); // Solo cuentas asignadas a esta sucursal
            })
            ->get();
        // *** FIN CORRECCIÓN ***

        // Se siguen necesitando todas para el modal de transferencias
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
