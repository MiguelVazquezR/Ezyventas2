<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\InventoryReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:products.access', only: ['index', 'generate', 'print']),
        ];
    }

    public function __construct(
        private readonly InventoryReportService $reportService,
    ) {}

    /**
     * Página de selección de reportes.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $subscriptionId = $user->branch->subscription_id;

        $categories = Category::where('subscription_id', $subscriptionId)
            ->where('type', 'product')
            ->get(['id', 'name']);

        // Si el request trae parámetros, generamos el reporte inline
        $report = null;
        if ($request->has('report_type')) {
            $report = $this->generateReportData($request);
        }

        return Inertia::render('Product/Reports', [
            'categories' => $categories,
            'report' => $report,
            'filters' => $request->only([
                'report_type', 'start_date', 'end_date', 'category_id',
                'order_by', 'group_by', 'min_stock', 'limit',
            ]),
        ]);
    }

    /**
     * Endpoint para abrir reporte en nueva pestaña (modo imprimible).
     */
    public function print(Request $request): Response
    {
        $report = $this->generateReportData($request);

        return Inertia::render('Product/Reports/PrintReport', [
            'report' => $report,
        ]);
    }

    /**
     * Endpoint JSON para el modal de reportes desde el Index de productos.
     */
    public function generate(Request $request): \Illuminate\Http\JsonResponse
    {
        $report = $this->generateReportData($request);

        $user = Auth::user();
        $categories = Category::where('subscription_id', $user->branch->subscription_id)
            ->where('type', 'product')
            ->get(['id', 'name']);

        return response()->json([
            'report' => $report,
            'categories' => $categories,
        ]);
    }

    /**
     * Genera los datos del reporte según los parámetros.
     */
    private function generateReportData(Request $request): ?array
    {
        $user = Auth::user();
        $branchId = $user->branch_id;

        $reportType = $request->input('report_type');
        if (!$reportType) {
            return null;
        }

        $startDate = $request->input('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $endDate = $request->input('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        $categoryId = $request->input('category_id');
        $orderBy = $request->input('order_by', 'quantity');
        $groupBy = $request->input('group_by', 'product');
        $minStock = (int) $request->input('min_stock', 1);
        $limit = (int) $request->input('limit', 50);

        return match ($reportType) {
            'dead_stock' => $this->reportService->deadStock($branchId, $startDate, $endDate, $categoryId, $minStock),
            'top_sellers' => $this->reportService->topSellers($branchId, $startDate, $endDate, $orderBy, $categoryId, $limit),
            'inventory_turnover' => $this->reportService->inventoryTurnover($branchId, $startDate, $endDate, $categoryId, $limit),
            'stockouts' => $this->reportService->stockouts($branchId, $startDate, $endDate, $categoryId),
            'inventory_valuation' => $this->reportService->inventoryValuation($branchId, $categoryId, $groupBy),
            'high_value_stagnant' => $this->reportService->highValueStagnant($branchId, $startDate, $endDate, $categoryId, $limit),
            'margin_by_product' => $this->reportService->marginByProduct($branchId, $startDate, $endDate, $categoryId, $limit),
            default => null,
        };
    }
}
