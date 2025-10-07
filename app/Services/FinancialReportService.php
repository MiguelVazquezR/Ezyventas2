<?php

namespace App\Services;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Transaction;
use Carbon\Carbon;
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
            $startWeek = $this->startDate->weekOfYear;
            $endWeek = $this->endDate->weekOfYear;
            if ($endWeek < $startWeek) $endWeek = $this->startDate->weeksInYear() + $endWeek;
            $labels = collect(range($startWeek, $endWeek))->map(fn($w) => "Semana {$w}");
            $sqlDateFormat = '%v';
        } else { // Por Mes
            $labels = collect(range(1, 12))->map(fn($m) => Carbon::create(null, $m)->month($m)->isoFormat('MMM'));
            $sqlDateFormat = '%c';
        }

        $sales = $this->queryChartPoints(Transaction::class, 'created_at', $sqlDateFormat, $labels);
        $payments = $this->queryChartPoints(Payment::class, 'payment_date', $sqlDateFormat, $labels);
        $expenses = $this->queryChartPoints(Expense::class, 'expense_date', $sqlDateFormat, $labels);
        
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
        return Transaction::where('branch_id', $this->branchId)
            ->where('status', 'completado')
            ->whereBetween('created_at', [$this->startDate, $this->endDate])
            ->groupBy('channel')
            ->select('channel', DB::raw('SUM(subtotal - total_discount + total_tax) as total'), DB::raw('COUNT(*) as count'))
            ->get()
            ->map(function ($channel) {
                return [
                    'channel' => $channel->channel->value,
                    'total' => $channel->total,
                    'count' => $channel->count,
                ];
            });
    }

    private function getExpensesByCategory()
    {
        return Expense::where('branch_id', $this->branchId)
            ->where('status', ExpenseStatus::PAID) // Usando el Enum de tu modelo
            ->whereBetween('expense_date', [$this->startDate, $this->endDate])
            ->with('category:id,name') // Solo necesitamos el nombre
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

    private function getComparisonPeriods(): array
    {
        $diffInDays = $this->startDate->diffInDays($this->endDate);
        $previousEnd = $this->startDate->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($diffInDays);

        return [
            'current' => ['start' => $this->startDate, 'end' => $this->endDate],
            'previous' => ['start' => $previousStart, 'end' => $previousEnd],
        ];
    }
    
    private function queryTotal(string $model, string $dateColumn, array $period): float
    {
        $query = $model::query();
        $sumField = $model === Transaction::class ? DB::raw('subtotal - total_discount + total_tax') : 'amount';

        if ($model === Transaction::class) {
            $query->where('branch_id', $this->branchId)->where('status', 'completado');
        } elseif ($model === Payment::class) {
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
        $sumField = $model === Transaction::class 
            ? DB::raw('SUM(subtotal - total_discount + total_tax) as total') 
            : DB::raw('SUM(amount) as total');
        
        $query = $model::query();

        if ($model === Transaction::class) {
            $query->where('branch_id', $this->branchId)->where('status', 'completado');
        } elseif ($model === Payment::class) {
            $query->where('status', 'completado')->whereHas('transaction', fn ($q) => $q->where('branch_id', $this->branchId));
        } elseif ($model === Expense::class) {
            $query->where('branch_id', $this->branchId)->where('status', ExpenseStatus::PAID);
        }

        $results = $query->whereBetween($dateColumn, [$this->startDate, $this->endDate])
            ->select(DB::raw("DATE_FORMAT({$dateColumn}, '{$sqlDateFormat}') as point"), $sumField)
            ->groupBy('point')
            ->pluck('total', 'point');
        
        $data = [];
        foreach ($labels as $index => $label) {
            $key = $this->getSqlKeyForLabel($sqlDateFormat, $index);
            $data[] = floatval($results[$key] ?? 0);
        }
        
        return $data;
    }

    private function getSqlKeyForLabel(string $sqlFormat, int $index): string
    {
        switch ($sqlFormat) {
            case '%k':
                $hours = array_merge(range(5, 23), range(0, 4));
                return strval($hours[$index]);
            case '%w':
                $dayMapping = [0 => '1', 1 => '2', 2 => '3', 3 => '4', 4 => '5', 5 => '6', 6 => '0']; // Map index to SQL %w
                return $dayMapping[$index];
            case '%v':
                return strval($this->startDate->weekOfYear + $index);
            case '%c':
                return strval($index + 1);
            default:
                return strval($index);
        }
    }
}