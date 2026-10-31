<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VisitMetricsRequest;
use App\Services\Visits\VisitMetricsService;
use Inertia\Inertia;
use Inertia\Response;

class AdminVisitController extends Controller
{
    public function __construct(
        private readonly VisitMetricsService $visitMetricsService,
    ) {}

    public function index(VisitMetricsRequest $request): Response
    {
        $period = $request->validated()['period'] ?? 'week';

        return Inertia::render('Admin/Visits/Index', [
            'period' => $period,
            ...$this->visitMetricsService->summary($period),
        ]);
    }
}
