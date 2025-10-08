<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class FinancialReportService
{
    protected $branchId;
    protected $startDate;
    protected $endDate;

    public function __construct(int $branchId, Carbon $startDate, Carbon $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate->copy()->startOfDay();
        $this->endDate = $endDate->copy()->endOfDay();
    }

    public function generateReportData(): array
    {
        $periods = $this->getComparisonPeriods();
        
        return [
            'kpis' => $this->getKpis($periods),
            'chartData' => $this->getChartData(),
            'paymentMethods' => $this->getPaymentMethodsDistribution(),
            'salesByChannel' => $this->getSalesByChannel(),
            'expensesByCategory' => $this->getExpensesByCategory(),
            'bankAccounts' => $this->getBankAccounts(), // <-- DATO AÑADIDO
            'filters' => ['startDate' => $this->startDate->toDateString(), 'endDate' => $this->endDate->toDateString()]
        ];
    }

    private function getKpis(array $periods): array
    {
        // Totales del periodo actual
        $currentSales = $this->queryTotal(Transaction::class, 'created_at', $periods['current']);
        $currentPayments = $this->queryTotal(Payment::class, 'payment_date', $periods['current']);
        $currentExpenses = $this->queryTotal(Expense::class, 'expense_date', $periods['current']);
        
        // Totales del periodo anterior
        $previousSales = $this->queryTotal(Transaction::class, 'created_at', $periods['previous']);
        $previousPayments = $this->queryTotal(Payment::class, 'payment_date', $periods['previous']);
        $previousExpenses = $this->queryTotal(Expense::class, 'expense_date', $periods['previous']);

        $currentProfit = $currentPayments - $currentExpenses;
        $previousProfit = $previousPayments - $previousExpenses;

        return [
            'sales' => $this->calculateKpiMetric($currentSales, $previousSales),
            'payments' => $this->calculateKpiMetric($currentPayments, $previousPayments),
            'expenses' => $this->calculateKpiMetric($currentExpenses, $previousExpenses),
            'profit' => $this->calculateKpiMetric($currentProfit, $previousProfit),
        ];
    }

    private function getChartData(): array
    {
        $diffInDays = $this->startDate->diffInDays($this->endDate);

        $labels = [];
        $sqlDateFormat = '';

        if ($this->startDate->isSameDay($this->endDate)) { // Por Hora
            $labels = collect(range(5, 23))->concat(range(0, 4))->map(fn($h) => Carbon::now()->startOfDay()->hour($h)->format('g A'));
            $sqlDateFormat = '%k';
        } elseif ($diffInDays <= 7) { // Por Día de la semana
            $labels = collect(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo']);
            $sqlDateFormat = '%w';
        } elseif ($diffInDays <= 31) { // Por Semana del mes
            $period = CarbonPeriod::create($this->startDate, '1 week', $this->endDate);
            foreach ($period as $date) {
                $startOfWeek = $date->copy()->startOfWeek(Carbon::MONDAY);
                $endOfWeek = $date->copy()->endOfWeek(Carbon::SUNDAY);
                
                $labelStart = $startOfWeek->isBefore($this->startDate) ? $this->startDate : $startOfWeek;
                $labelEnd = $endOfWeek->isAfter($this->endDate) ? $this->endDate : $endOfWeek;
                
                $labels[] = "Sem " . $labelStart->format('W') . " (" . $labelStart->format('d') . ' - ' . $labelEnd->format('d M') . ")";
            }
            $sqlDateFormat = '%v'; // Semana del año (Lunes como primer día)
        } else { // Por Mes
            $labels = collect(range(1, 12))->map(fn($m) => Carbon::create(null, $m)->month($m)->isoFormat('MMM'));
            $sqlDateFormat = '%c';
        }

        $sales = $this->queryChartPoints(Transaction::class, 'created_at', $sqlDateFormat, $labels);
        $payments = $this->queryChartPoints(Payment::class, 'payment_date', $sqlDateFormat, $labels);
        $expenses = $this->queryChartPoints(Expense::class, 'created_at', $sqlDateFormat, $labels);
        
        return ['labels' => $labels, 'sales' => $sales, 'payments' => $payments, 'expenses' => $expenses];
    }

    private function getPaymentMethodsDistribution()
    {
        $payments = Payment::where('status', 'completado')
            ->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId))
            ->whereBetween('payment_date', [$this->startDate, $this->endDate])
            ->groupBy('payment_method')
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->get();
        
        $totalPayments = $payments->sum('total');

        if ($totalPayments == 0) return [];
        
        return $payments->map(function ($payment) use ($totalPayments) {
            return [
                'method' => $payment->payment_method->value,
                'total' => $payment->total,
                'percentage' => round(($payment->total / $totalPayments) * 100),
            ];
        });
    }

    private function getSalesByChannel()
    {
        $results = Transaction::where('branch_id', $this->branchId)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->groupBy('channel')
            ->select(
                'channel',
                DB::raw('SUM(subtotal) as total_subtotal'),
                DB::raw('SUM(total_discount) as total_total_discount'),
                DB::raw('SUM(total_tax) as total_total_tax'),
                DB::raw('COUNT(*) as count')
            )
            ->get();

        return $results->map(function ($channel) {
            $total = ($channel->total_subtotal ?? 0) - ($channel->total_total_discount ?? 0) + ($channel->total_total_tax ?? 0);
            return [
                'channel' => $channel->channel->value,
                'total' => $total,
                'count' => $channel->count,
            ];
        });
    }

    private function getExpensesByCategory()
    {
        return Expense::where('branch_id', $this->branchId)
            ->where('status', ExpenseStatus::PAID)
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->with('category:id,name')
            ->groupBy('expense_category_id')
            ->select('expense_category_id', DB::raw('SUM(amount) as total'))
            ->get()
            ->map(function ($expense) {
                return [
                    'category_name' => $expense->category->name ?? 'Sin Categoría',
                    'total' => $expense->total,
                ];
            });
    }

    // --- INICIO DE CAMBIO: MÉTODO PARA OBTENER CUENTAS BANCARIAS ---
    private function getBankAccounts()
    {
        // Las cuentas bancarias pertenecen a la suscripción, no a la sucursal.
        // Obtenemos el ID de la suscripción a partir del ID de la sucursal.
        $subscriptionId = Branch::find($this->branchId)->subscription_id;

        // Devolvemos todas las cuentas bancarias de esa suscripción.
        return BankAccount::where('subscription_id', $subscriptionId)->get();
    }
    // --- FIN DE CAMBIO ---

    private function getComparisonPeriods(): array
    {
        // --- CORRECCIÓN: Lógica mejorada para calcular el periodo anterior ---
        $daysInPeriod = $this->startDate->diffInDays($this->endDate);
        $previousEnd = $this->startDate->copy()->subDay()->endOfDay();
        $previousStart = $this->startDate->copy()->subDays($daysInPeriod + 1)->startOfDay();

        return [
            'current' => ['start' => $this->startDate, 'end' => $this->endDate],
            'previous' => ['start' => $previousStart, 'end' => $previousEnd],
        ];
    }
    
    private function queryTotal(string $model, string $dateColumn, array $period): float
    {
        $query = $model::query();
        
        $sumField = 'amount';
        if ($model === Transaction::class) {
            $result = $query->where('branch_id', $this->branchId)
                ->whereBetween($dateColumn, [$period['start'], $period['end']])
                ->select(
                    DB::raw('SUM(subtotal) as total_subtotal'),
                    DB::raw('SUM(total_discount) as total_total_discount'),
                    DB::raw('SUM(total_tax) as total_total_tax')
                )->first();
            return ($result->total_subtotal ?? 0) - ($result->total_total_discount ?? 0) + ($result->total_total_tax ?? 0);
        }

        if ($model === Payment::class) {
            $query->where('status', 'completado')
                  ->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId));
        } elseif ($model === Expense::class) {
            $query->where('branch_id', $this->branchId)->where('status', ExpenseStatus::PAID);
        }
        
        return $query->whereBetween($dateColumn, [$period['start'], $period['end']])->sum($sumField);
    }

    private function calculateKpiMetric(float $current, float $previous): array
    {
        $monetaryChange = $current - $previous;
        $percentageChange = $previous != 0 ? ($monetaryChange / abs($previous)) * 100 : ($current > 0 ? 100 : 0);
        return [
            'current' => $current,
            'previous' => $previous,
            'monetary_change' => $monetaryChange,
            'percentage_change' => round($percentageChange, 2),
        ];
    }
    
    private function queryChartPoints(string $model, string $dateColumn, string $sqlDateFormat, $labels)
    {
        $query = $model::query();

        if ($model === Transaction::class) {
            // --- CORRECCIÓN: Consulta robusta para Ventas Totales ---
            $results = $query->where('branch_id', $this->branchId)
                ->whereBetween($dateColumn, [$this->startDate, $this->endDate])
                ->select(
                    DB::raw("DATE_FORMAT({$dateColumn}, '{$sqlDateFormat}') as point"),
                    DB::raw('SUM(subtotal) as total_subtotal'),
                    DB::raw('SUM(total_discount) as total_total_discount'),
                    DB::raw('SUM(total_tax) as total_total_tax')
                )
                ->groupBy('point')
                ->get()
                ->keyBy('point');
            
            $data = [];
            foreach ($labels as $index => $label) {
                $key = $this->getSqlKeyForLabel($sqlDateFormat, $index, $label);
                if (isset($results[$key])) {
                    $result = $results[$key];
                    $data[] = floatval(($result->total_subtotal ?? 0) - ($result->total_total_discount ?? 0) + ($result->total_total_tax ?? 0));
                } else {
                    $data[] = 0;
                }
            }
            return $data;

        } elseif ($model === Payment::class) {
            $query->where('status', 'completado')->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId));
        } elseif ($model === Expense::class) {
            $query->where('branch_id', $this->branchId)->where('status', ExpenseStatus::PAID);
        }
        
        $results = $query->whereBetween($dateColumn, [$this->startDate, $this->endDate])
            ->select(DB::raw("DATE_FORMAT({$dateColumn}, '{$sqlDateFormat}') as point"), DB::raw('SUM(amount) as total'))
            ->groupBy('point')
            ->pluck('total', 'point');
        
        $data = [];
        foreach ($labels as $index => $label) {
            $key = $this->getSqlKeyForLabel($sqlDateFormat, $index, $label);
            $data[] = floatval($results[$key] ?? 0);
        }
        
        return $data;
    }

    private function getSqlKeyForLabel(string $sqlFormat, int $index, string $label): string
    {
        switch ($sqlFormat) {
            case '%k':
                $hours = array_merge(range(5, 23), range(0, 4));
                return strval($hours[$index]);
            case '%w':
                $dayMapping = [0 => '1', 1 => '2', 2 => '3', 3 => '4', 4 => '5', 5 => '6', 6 => '0']; // Map index to SQL %w
                return $dayMapping[$index];
            case '%v':
                preg_match('/Sem (\d+)/', $label, $matches);
                return $matches[1] ?? '0';
            case '%c':
                return strval($index + 1);
            default:
                return strval($index);
        }
    }
}