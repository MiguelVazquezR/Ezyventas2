<?php

namespace App\Services\Visits;

use App\Models\SiteVisit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class VisitMetricsService
{
    /**
     * Build the visit metrics payload for the admin panel.
     */
    public function summary(string $period = 'week'): array
    {
        [$start, $end] = $this->range($period);

        return [
            'metrics' => [
                'periodVisits' => SiteVisit::betweenDates($start, $end)->count(),
                'periodVisitors' => SiteVisit::betweenDates($start, $end)->uniqueVisitors()->count('visitor_hash'),
                'totalVisits' => SiteVisit::count(),
                'totalVisitors' => SiteVisit::uniqueVisitors()->count('visitor_hash'),
                'periodLabel' => $this->label($period),
            ],
            'chartData' => $this->chartData($period, $start, $end),
            'topPages' => $this->topPages($start, $end),
        ];
    }

    private function range(string $period): array
    {
        return match ($period) {
            'day' => [now()->startOfDay(), now()->endOfDay()],
            'month' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            default => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
        };
    }

    private function label(string $period): string
    {
        return match ($period) {
            'day' => 'Hoy',
            'month' => 'Últimos 30 días',
            default => 'Últimos 7 días',
        };
    }

    /**
     * Visits grouped by hour (day) or by day (week/month), filling empty buckets.
     */
    private function chartData(string $period, Carbon $start, Carbon $end): array
    {
        $buckets = [];
        $cursor = $start->copy();

        if ($period === 'day') {
            while ($cursor <= $end) {
                $buckets[$cursor->format('Y-m-d H:00')] = [
                    'label' => $cursor->format('H:00'),
                    'visits' => 0,
                    'visitors' => 0,
                ];
                $cursor->addHour();
            }

            $rows = $this->groupedRows("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')", $start, $end);
        } else {
            while ($cursor <= $end) {
                $buckets[$cursor->format('Y-m-d')] = [
                    'label' => $cursor->translatedFormat('d M'),
                    'visits' => 0,
                    'visitors' => 0,
                ];
                $cursor->addDay();
            }

            $rows = $this->groupedRows('DATE(created_at)', $start, $end);
        }

        foreach ($rows as $row) {
            if (isset($buckets[$row->bucket])) {
                $buckets[$row->bucket]['visits'] = (int) $row->visits;
                $buckets[$row->bucket]['visitors'] = (int) $row->visitors;
            }
        }

        return array_values($buckets);
    }

    private function groupedRows(string $bucketExpression, Carbon $start, Carbon $end): Collection
    {
        return SiteVisit::betweenDates($start, $end)
            ->selectRaw("{$bucketExpression} as bucket, COUNT(*) as visits, COUNT(DISTINCT visitor_hash) as visitors")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();
    }

    /**
     * Most visited public pages during the period.
     */
    private function topPages(Carbon $start, Carbon $end): array
    {
        return SiteVisit::betweenDates($start, $end)
            ->selectRaw('path, COUNT(*) as visits, COUNT(DISTINCT visitor_hash) as visitors')
            ->groupBy('path')
            ->orderByDesc('visits')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'path' => $row->path,
                'visits' => (int) $row->visits,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }
}
