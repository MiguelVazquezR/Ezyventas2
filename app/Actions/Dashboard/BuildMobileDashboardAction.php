<?php

namespace App\Actions\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardAlertService;
use App\Services\Dashboard\DashboardMetricsService;

/**
 * Payload of the home screen of the mobile app.
 *
 * The whole screen is one request, but every block is gated by its own
 * permission: a block the user may not see comes back as `null` (never
 * missing), so the app only has to check for null to hide a tile.
 */
class BuildMobileDashboardAction
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly DashboardAlertService $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        $branchId = (int) $user->branch_id;

        return [
            'generated_at' => now()->toISOString(),
            'sales' => $user->can('dashboard.see_sales')
                ? $this->metrics->salesSummary($branchId)
                : null,
            'layaways' => $user->can('dashboard.see_layaways')
                ? ['expiring_count' => $this->alerts->expiringLayawaysCount($branchId)]
                : null,
            'orders' => $user->can('dashboard.see_orders')
                ? ['upcoming_deliveries_count' => $this->alerts->upcomingDeliveriesCount($branchId)]
                : null,
            'receivables' => $user->can('dashboard.see_outstanding_balances')
                ? ['total_customer_debt' => $this->metrics->totalCustomerDebt($branchId)]
                : null,
            'inventory' => $user->can('dashboard.see_inventory_details')
                ? $this->metrics->inventorySummary($branchId)
                : null,
            'service_orders' => $user->can('services.orders.access')
                ? $this->metrics->serviceOrdersByStatus($branchId)
                : null,
            'cash_register' => $this->metrics->cashRegisterState($user),
        ];
    }
}
