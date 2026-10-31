<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Enums\TransactionStatus;
use App\Exports\FinancialReportExport;
use App\Http\Requests\FinancialReports\ExportFinancialReportRequest;
use App\Http\Requests\FinancialReports\FinancialReportIndexRequest;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Services\FinancialReportService;
use App\Services\FinancialReports\PeriodComparisonService;
use Carbon\Carbon;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinancialReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly PeriodComparisonService $periodComparisonService,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:financial_reports.access'),
        ];
    }

    public function index(FinancialReportIndexRequest $request): Response
    {
        $user = Auth::user();
        $branchId = $user->branch_id;
        $validated = $request->validated();
        $range = $validated['range'] ?? 'custom';

        if (isset($validated['start_date'])) {
            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        } else {
            [$startDate, $endDate] = match ($range) {
                'week' => [Carbon::today()->startOfWeek(Carbon::MONDAY), Carbon::today()->endOfWeek(Carbon::SUNDAY)],
                'month' => [Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth()],
                'year' => [Carbon::today()->startOfYear(), Carbon::today()->endOfYear()],
                default => [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()],
            };
        }

        $comparison = $this->periodComparisonService->resolve($startDate, $endDate, $range);

        // 1. KPIs (Servicio de Reporte)
        $reportService = new FinancialReportService(
            $branchId,
            $startDate,
            $endDate,
            $comparison['previous'],
        );
        $reportData = $reportService->generateReportData();

        // 2. Variaciones de ganancia del negocio y ganancia total
        // Ganancia del negocio: ventas - gastos pagados con dinero del negocio.
        // Ganancia total: ventas - todos los gastos, incluidos los externos.
        $netProfitCurrent = $reportData['kpis']['sales']['current'] - $reportData['kpis']['expenses']['current'];
        $netProfitPrevious = $reportData['kpis']['sales']['previous'] - $reportData['kpis']['expenses']['previous'];

        $allExpensesCurrent = $reportData['kpis']['expenses']['current'] + ($reportData['kpis']['externalExpenses']['current'] ?? 0);
        $allExpensesPrevious = $reportData['kpis']['expenses']['previous'] + ($reportData['kpis']['externalExpenses']['previous'] ?? 0);

        $netProfitAllCurrent = $reportData['kpis']['sales']['current'] - $allExpensesCurrent;
        $netProfitAllPrevious = $reportData['kpis']['sales']['previous'] - $allExpensesPrevious;

        $reportData['kpis']['netProfit'] = [
            'current' => $netProfitCurrent,
            'previous' => $netProfitPrevious,
            'monetary_change' => $netProfitCurrent - $netProfitPrevious,
            'percentage_change' => $netProfitPrevious != 0
                ? round((($netProfitCurrent - $netProfitPrevious) / abs($netProfitPrevious)) * 100, 2)
                : ($netProfitCurrent != 0 ? 100 : 0),
        ];

        $reportData['kpis']['netProfitAll'] = [
            'current' => $netProfitAllCurrent,
            'previous' => $netProfitAllPrevious,
        ];

        // Margen del negocio: ganancia del negocio / ventas.
        // Margen total: ganancia total (incluye gastos externos) / ventas.
        $salesCurrent = $reportData['kpis']['sales']['current'];
        $salesPrevious = $reportData['kpis']['sales']['previous'];

        $utilityMarginCurrent = $salesCurrent != 0
            ? round(($netProfitCurrent / $salesCurrent) * 100, 2)
            : 0;

        $utilityMarginPrevious = $salesPrevious != 0
            ? round(($netProfitPrevious / $salesPrevious) * 100, 2)
            : 0;

        $utilityMarginAllCurrent = $salesCurrent != 0
            ? round(($netProfitAllCurrent / $salesCurrent) * 100, 2)
            : 0;

        $utilityMarginAllPrevious = $salesPrevious != 0
            ? round(($netProfitAllPrevious / $salesPrevious) * 100, 2)
            : 0;

        $reportData['kpis']['utilityMargin'] = [
            'current' => $utilityMarginCurrent,
            'previous' => $utilityMarginPrevious,
            'change' => round($utilityMarginCurrent - $utilityMarginPrevious, 2),
            'allCurrent' => $utilityMarginAllCurrent,
            'allPrevious' => $utilityMarginAllPrevious,
        ];

        $reportData['comparison'] = [
            'label' => $comparison['label'],
            'start_date' => $comparison['previous']['start']->toDateString(),
            'end_date' => $comparison['previous']['end']->toDateString(),
        ];

        // 3. Ticket Promedio (Optimizado con índices)
        $salesCountCurrent = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED, TransactionStatus::CHANGED])
            ->count();

        $averageTicketCurrent = $salesCountCurrent > 0 ? $reportData['kpis']['sales']['current'] / $salesCountCurrent : 0;

        $salesCountPrevious = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$comparison['previous']['start'], $comparison['previous']['end']])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED, TransactionStatus::CHANGED])
            ->count();

        $averageTicketPrevious = $salesCountPrevious > 0 ? $reportData['kpis']['sales']['previous'] / $salesCountPrevious : 0;

        $reportData['kpis']['averageTicket'] = [
            'current' => $averageTicketCurrent,
            'previous' => $averageTicketPrevious,
            'monetary_change' => $averageTicketCurrent - $averageTicketPrevious,
            'percentage_change' => $averageTicketPrevious != 0
                ? round((($averageTicketCurrent - $averageTicketPrevious) / abs($averageTicketPrevious)) * 100, 2)
                : ($averageTicketCurrent != 0 ? 100 : 0),
        ];


        // --- OPTIMIZACIÓN CRÍTICA: DATOS DETALLADOS ---
        $limitWeb = 1000;

        // Gastos Detallados
        $reportData['detailedExpenses'] = Expense::where('branch_id', $branchId)
            ->where('status', ExpenseStatus::PAID->value)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select('id', 'branch_id', 'expense_date', 'expense_category_id', 'description', 'amount', 'payment_method', 'bank_account_id', 'folio', 'is_external')
            ->with(['category:id,name', 'bankAccount:id,account_name,bank_name'])
            ->orderBy('expense_date', 'desc')
            ->limit($limitWeb)
            ->get();

        // Ventas Detalladas
        $reportData['detailedTransactions'] = Transaction::where('branch_id', $branchId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNotIn('status', [TransactionStatus::CANCELLED, TransactionStatus::REFUNDED, TransactionStatus::CHANGED])
            ->select('id', 'branch_id', 'customer_id', 'created_at', 'folio', 'channel', 'status', 'subtotal', 'total_discount', 'total_tax')
            ->selectRaw('(subtotal - total_discount + total_tax) as total') 
            ->with(['customer:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit($limitWeb)
            ->get();

        // Pagos Detallados
        $reportData['detailedPayments'] = $reportService->getDetailedPayments($limitWeb);

        // --- Fin Datos Detallados ---

        $subscriptionId = $user->branch->subscription_id;

        // Cuentas Bancarias
        $reportData['bankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)
            ->whereHas('branches', fn($q) => $q->where('branch_id', $branchId))
            ->get();

        $reportData['allBankAccounts'] = BankAccount::where('subscription_id', $subscriptionId)->get();

        return Inertia::render('FinancialControl/Index', $reportData);
    }

    public function export(ExportFinancialReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();

        $startDate = Carbon::parse($validated['start_date'])->startOfDay();
        $endDate = Carbon::parse($validated['end_date'])->endOfDay();
        $branchId = Auth::user()->branch_id;

        $fileName = 'Reporte financiero ' . $startDate->format('d-m-Y') . ' al ' . $endDate->format('d-m-Y') . '.xlsx';

        return Excel::download(new FinancialReportExport($branchId, $startDate, $endDate), $fileName);
    }
}