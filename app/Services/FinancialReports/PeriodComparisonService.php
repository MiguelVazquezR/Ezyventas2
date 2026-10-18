<?php

namespace App\Services\FinancialReports;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves the period that must be compared against the selected date range.
 *
 * Presets compare with the immediately previous calendar unit (previous day,
 * week, month or year). Custom ranges compare with a range of the same length
 * placed immediately before the current one.
 */
class PeriodComparisonService
{
    public function resolve(CarbonInterface $startDate, CarbonInterface $endDate, string $range = 'custom'): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $previousEnd = $start->copy()->subSecond();

        if ($range === 'custom') {
            $days = (int) round($start->floatDiffInDays($end->copy()->addSecond()));
            $days = max(1, $days);
            $previousStart = $start->copy()->subDays($days);
            $label = 'periodo anterior';
        } else {
            [$previousStart, $label] = match ($range) {
                'day' => [$start->copy()->subDay(), 'día anterior'],
                'week' => [$start->copy()->subWeek(), 'semana anterior'],
                'month' => [$start->copy()->subMonthNoOverflow(), 'mes anterior'],
                'year' => [$start->copy()->subYear(), 'año anterior'],
                default => [$start->copy()->subDay(), 'día anterior'],
            };
        }

        return [
            'current' => [
                'start' => $start,
                'end' => $end,
            ],
            'previous' => [
                'start' => $previousStart->copy()->startOfDay(),
                'end' => $previousEnd,
            ],
            'label' => $label,
        ];
    }
}
