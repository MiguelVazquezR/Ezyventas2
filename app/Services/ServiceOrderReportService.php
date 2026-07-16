<?php

namespace App\Services;

use App\Models\ServiceOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ServiceOrderReportService
{
    public function getStatusSummary(int $branchId): array
    {
        return ServiceOrder::where('branch_id', $branchId)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status->value,
                'count'  => (int) $row->count,
            ])
            ->toArray();
    }

    public function getWorkloadByTechnician(int $branchId, Carbon $start, Carbon $end): array
    {
        // Note: technician_name is a string field, not a user FK
        return ServiceOrder::where('branch_id', $branchId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('technician_name')
            ->select(
                'technician_name',
                DB::raw('COUNT(*) as total_orders'),
                DB::raw("SUM(CASE WHEN status = 'entregado' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN status = 'en_progreso' THEN 1 ELSE 0 END) as in_progress"),
                DB::raw("SUM(CASE WHEN status = 'pendiente' THEN 1 ELSE 0 END) as pending"),
            )
            ->groupBy('technician_name')
            ->orderByDesc('total_orders')
            ->get()
            ->map(fn ($row) => [
                'technician'  => $row->technician_name,
                'total_orders'=> (int) $row->total_orders,
                'completed'   => (int) $row->completed,
                'in_progress' => (int) $row->in_progress,
                'pending'     => (int) $row->pending,
            ])
            ->toArray();
    }

    public function getAverageTurnaroundTime(int $branchId, Carbon $start, Carbon $end): array
    {
        // Since there's no completed_at column, use activity log or updated_at for delivered orders
        $deliveredOrders = ServiceOrder::where('branch_id', $branchId)
            ->where('status', 'entregado')
            ->whereBetween('updated_at', [$start, $end])
            ->get(['id', 'folio', 'created_at', 'updated_at']);

        $averageHours = $deliveredOrders->avg(function ($order) {
            return $order->created_at->diffInHours($order->updated_at);
        });

        return [
            'delivered_count'         => $deliveredOrders->count(),
            'average_turnaround_hours'=> round($averageHours ?? 0, 1),
            'period'                  => [
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
        ];
    }
}
