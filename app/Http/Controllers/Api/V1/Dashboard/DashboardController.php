<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Actions\Dashboard\BuildMobileDashboardAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\ExpiringLayawaysRequest;
use App\Http\Requests\Api\V1\Dashboard\ShowDashboardRequest;
use App\Http\Requests\Api\V1\Dashboard\UpcomingDeliveriesRequest;
use App\Services\Dashboard\DashboardAlertService;
use Illuminate\Http\JsonResponse;

/**
 * Home screen of the mobile app: KPIs, alerts and the state of the cash
 * register the user is working in.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly BuildMobileDashboardAction $buildDashboard,
        private readonly DashboardAlertService $alerts,
    ) {}

    public function index(ShowDashboardRequest $request): JsonResponse
    {
        return response()->json($this->buildDashboard->execute($request->user()));
    }

    public function expiringLayaways(ExpiringLayawaysRequest $request): JsonResponse
    {
        $days = (int) $request->validated('days');

        return response()->json([
            'days' => $days,
            'data' => $this->alerts->expiringLayaways((int) $request->user()->branch_id, $days),
        ]);
    }

    public function upcomingDeliveries(UpcomingDeliveriesRequest $request): JsonResponse
    {
        $days = (int) $request->validated('days');

        return response()->json([
            'days' => $days,
            'data' => $this->alerts->upcomingDeliveries((int) $request->user()->branch_id, $days),
        ]);
    }
}
